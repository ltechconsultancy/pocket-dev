<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Session;
use App\Models\SubAgentTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Sub-agents of a session for the "N sub-agents actief" pill and sheet:
 * status, timing and what each running sub-agent is doing right now.
 *
 * Live activity comes from the child conversation's Redis stream: the latest
 * tool_use_start (+ its input deltas), or thinking/text. Read backwards in small
 * chunks so large tool results (screenshots) are rarely loaded.
 */
class SubAgentMonitor
{
    private const FINISHED_WINDOW_MINUTES = 120;
    private const MAX_FINISHED = 5;
    private const MAX_DEPTH = 3;
    private const STEPS = 5;

    public function __construct(private StreamManager $streams)
    {
    }

    public function forSession(Session $session): array
    {
        $parentUuids = $session->screens()
            ->where('type', 'chat')
            ->with('conversation:id,uuid')
            ->get()
            ->pluck('conversation.uuid')
            ->filter()
            ->values()
            ->all();

        $tasks = $this->collectTasks($parentUuids);

        $running = $tasks->filter(fn(SubAgentTask $t) => in_array($this->status($t), ['running', 'pending'], true));
        $finished = $tasks->reject(fn(SubAgentTask $t) => in_array($this->status($t), ['running', 'pending'], true))
            ->filter(fn(SubAgentTask $t) => $this->finishedAt($t)?->gt(now()->subMinutes(self::FINISHED_WINDOW_MINUTES)))
            ->sortByDesc(fn(SubAgentTask $t) => $this->finishedAt($t))
            ->take(self::MAX_FINISHED);

        return [
            'running' => $running->count(),
            'items' => $running->sortBy('created_at')->concat($finished)
                ->map(fn(SubAgentTask $t) => $this->present($t))
                ->values()
                ->all(),
        ];
    }

    /**
     * Running sub-agents for the badges in the session list and the chat tabs.
     * Nested sub-agents count for the chat (and session) at the top of the chain.
     *
     * @return array{sessions: array<string,int>, conversations: array<string,int>}
     */
    public function runningCounts(): array
    {
        $running = SubAgentTask::whereNull('cancelled_at')
            ->where('created_at', '>', now()->subDays(2))
            ->whereHas('childConversation', fn($q) => $q->where('status', Conversation::STATUS_PROCESSING))
            ->pluck('parent_conversation_uuid');

        $sessions = [];
        $conversations = [];
        foreach ($running as $parentUuid) {
            [$chatUuid, $sessionId] = $this->topChatOf((string) $parentUuid);
            if ($sessionId) {
                $sessions[$sessionId] = ($sessions[$sessionId] ?? 0) + 1;
                $conversations[$chatUuid] = ($conversations[$chatUuid] ?? 0) + 1;
            }
        }

        return ['sessions' => $sessions, 'conversations' => $conversations];
    }

    /**
     * The chat tab (conversation with a screen) at the top of a sub-agent chain,
     * and its session. A sub-agent's own conversation has no screen.
     *
     * @return array{0: ?string, 1: ?string} [chat conversation uuid, session id]
     */
    private function topChatOf(string $conversationUuid): array
    {
        for ($depth = 0; $depth <= self::MAX_DEPTH && $conversationUuid; $depth++) {
            $conversation = Conversation::where('uuid', $conversationUuid)
                ->with('screen:id,conversation_id,session_id')
                ->first(['id', 'uuid']);
            if ($conversation?->screen?->session_id) {
                return [$conversation->uuid, $conversation->screen->session_id];
            }
            $conversationUuid = (string) SubAgentTask::where('child_conversation_uuid', $conversationUuid)->value('parent_conversation_uuid');
        }

        return [null, null];
    }

    /**
     * Tasks spawned from the session's chats, plus sub-agents of those sub-agents.
     */
    private function collectTasks(array $parentUuids): Collection
    {
        $all = collect();
        $seen = [];

        for ($depth = 0; $depth < self::MAX_DEPTH && !empty($parentUuids); $depth++) {
            $tasks = SubAgentTask::with(['agent:id,name', 'childConversation:id,uuid,status,updated_at'])
                ->whereIn('parent_conversation_uuid', $parentUuids)
                ->where('created_at', '>', now()->subDays(2))
                ->get()
                ->reject(fn($t) => isset($seen[$t->id]));

            foreach ($tasks as $task) {
                $seen[$task->id] = true;
            }
            $all = $all->concat($tasks);
            $parentUuids = $tasks->pluck('child_conversation_uuid')->filter()->values()->all();
        }

        return $all;
    }

    private function status(SubAgentTask $task): string
    {
        if ($task->cancelled_at) {
            return 'cancelled';
        }

        return $task->getStatus();
    }

    private function finishedAt(SubAgentTask $task): ?\Carbon\Carbon
    {
        return $task->cancelled_at ?? $task->childConversation?->updated_at;
    }

    private function present(SubAgentTask $task): array
    {
        $status = $this->status($task);
        $active = in_array($status, ['running', 'pending'], true);
        $end = $active ? now() : ($this->finishedAt($task) ?? now());

        return [
            'id' => $task->id,
            'title' => $this->title((string) $task->prompt),
            'agent' => $task->agent?->name ?? 'Sub-agent',
            'status' => $status,
            'background' => (bool) $task->is_background,
            'started_at' => $task->created_at?->toIso8601String(),
            'duration_seconds' => max(0, $end->getTimestamp() - $task->created_at->getTimestamp()),
            'delivered' => (bool) $task->result_delivered_at,
            'steps' => $active && $task->child_conversation_uuid ? $this->recentSteps($task->child_conversation_uuid) : [],
            'error' => $status === 'failed' ? Str::limit((string) $task->getError(), 160) : null,
        ];
    }

    /**
     * First meaningful line of the prompt, without markdown headings or a "Opdracht:" prefix.
     */
    private function title(string $prompt): string
    {
        foreach (preg_split('/\R/', $prompt) as $line) {
            $line = trim(preg_replace('/^[#>*\-\s]+/', '', $line));
            $line = preg_replace('/^(opdracht|taak|task)\s*:\s*/i', '', $line);
            if ($line !== '') {
                return Str::limit($line, 80);
            }
        }

        return 'Sub-agent';
    }

    /**
     * Latest steps (newest first): tools with a short argument, or thinking/writing.
     *
     * @return array<int, array{label: string, done: bool}>
     */
    private function recentSteps(string $conversationUuid): array
    {
        $key = 'stream:' . $conversationUuid . ':events';
        $total = (int) Redis::llen($key);
        if ($total === 0) {
            return [['label' => 'Starten…', 'done' => false]];
        }

        $steps = [];
        $deltas = []; // block_index => input JSON, assembled while walking backwards
        $chunk = 40;
        $end = $total - 1;

        while ($end >= 0 && count($steps) < self::STEPS && ($total - $end) <= 600) {
            $start = max(0, $end - $chunk + 1);
            $events = array_reverse(Redis::lrange($key, $start, $end));
            foreach ($events as $raw) {
                $event = json_decode($raw, true);
                $type = $event['type'] ?? '';
                $block = $event['block_index'] ?? null;

                if ($type === 'tool_use_delta' && $block !== null) {
                    $deltas[$block] = ($event['content'] ?? '') . ($deltas[$block] ?? '');
                } elseif ($type === 'tool_use_start') {
                    $name = $event['metadata']['tool_name'] ?? 'Tool';
                    $steps[] = ['label' => $this->toolLabel($name, $deltas[$block] ?? ''), 'done' => true];
                } elseif (in_array($type, ['thinking_start', 'text_start'], true) && empty($steps)) {
                    $steps[] = ['label' => $type === 'thinking_start' ? 'Denkt na…' : 'Schrijft antwoord…', 'done' => false];
                }
                if (count($steps) >= self::STEPS) {
                    break;
                }
            }
            $end = $start - 1;
        }

        // Only the newest step can still be in progress
        foreach ($steps as $i => $step) {
            $steps[$i]['done'] = $i > 0;
        }

        return $steps;
    }

    private function toolLabel(string $name, string $inputJson): string
    {
        $input = json_decode($inputJson, true);
        if (!is_array($input)) {
            // Partial JSON while the input is still streaming: pull the first string value
            preg_match('/"(?:file_path|path|command|pattern|url|query|description)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/', $inputJson, $m);
            $input = isset($m[1]) ? ['_' => stripcslashes($m[1])] : [];
        }

        $arg = $input['file_path'] ?? $input['path'] ?? $input['notebook_path'] ?? null;
        if ($arg) {
            $arg = basename($arg);
        } else {
            $arg = $input['command'] ?? $input['pattern'] ?? $input['url'] ?? $input['query']
                ?? $input['description'] ?? $input['_'] ?? '';
        }
        $arg = Str::limit(trim(preg_replace('/\s+/', ' ', (string) $arg)), 70);

        return $arg === '' ? $name : "{$name} · {$arg}";
    }
}
