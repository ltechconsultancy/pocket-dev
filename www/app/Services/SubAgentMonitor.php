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
        $parents = $session->screens()
            ->where('type', 'chat')
            ->with('conversation:id,uuid,status,provider_type,provider_session_id')
            ->get()
            ->pluck('conversation')
            ->filter()
            ->values();
        $parentUuids = $parents->pluck('uuid')->all();

        $tasks = $this->collectTasks($parentUuids);

        $running = $tasks->filter(fn(SubAgentTask $t) => in_array($this->status($t), ['running', 'pending'], true));
        $finished = $tasks->reject(fn(SubAgentTask $t) => in_array($this->status($t), ['running', 'pending'], true))
            ->filter(fn(SubAgentTask $t) => $this->finishedAt($t)?->gt(now()->subMinutes(self::FINISHED_WINDOW_MINUTES)))
            ->map(fn(SubAgentTask $t) => ['at' => $this->finishedAt($t)?->getTimestamp() ?? 0, 'item' => $this->present($t)]);

        // Claude Code's own sub-agents (built-in Agent tool) of these chats
        $native = collect(app(NativeSubAgentMonitor::class)->forConversations($parents));
        $nativeRunning = $native->where('status', 'running');
        $nativeFinished = $native->where('status', '!=', 'running')
            ->map(fn(array $i) => ['at' => strtotime((string) $i['finished_at']) ?: 0, 'item' => $i]);

        $finishedItems = $finished->concat($nativeFinished)
            ->sortByDesc('at')
            ->take(self::MAX_FINISHED)
            ->pluck('item');

        return [
            'running' => $running->count() + $nativeRunning->count(),
            'items' => $running->sortBy('created_at')
                ->map(fn(SubAgentTask $t) => $this->present($t))
                ->concat($nativeRunning->sortBy('started_at')->values())
                ->concat($finishedItems)
                ->values()
                ->all(),
        ];
    }

    /**
     * Full history of a sub-agent for the viewer, as compact stream events.
     *
     * from=0: saved turns from the database (converted to the same event shape)
     * plus the live stream of the current turn. from>0: only new live events.
     * The client builds the timeline (thinking, text, tool calls + results).
     */
    public function events(SubAgentTask $task, int $from, int $limit = 3000): array
    {
        $child = $task->childConversation;
        $status = $this->status($task);
        $uuid = $task->child_conversation_uuid;
        $streaming = $uuid && $this->streams->isStreaming($uuid);
        $total = $uuid ? $this->streams->getEventCount($uuid) : 0;

        $events = [];
        if ($from === 0) {
            $events[] = ['type' => 'user', 'content' => Str::limit((string) $task->prompt, 20000)];
            if ($child) {
                // While streaming, the current turn is not in the DB yet; skip its user message
                // (the prompt above) and take the live part from the stream below.
                foreach ($this->messageEvents($child, skipFirstUser: true) as $event) {
                    $events[] = $event;
                }
            }
        }

        $next = $from;
        if ($streaming || ($from > 0 && $total > $from)) {
            $start = max($from, 0);
            $raw = $total > $start ? Redis::lrange('stream:' . $uuid . ':events', $start, $start + $limit - 1) : [];
            foreach ($raw as $line) {
                $compact = $this->compactEvent(json_decode($line, true) ?: []);
                if (!$compact) {
                    continue;
                }
                // Merge runs of deltas for the same block (thousands of tiny events otherwise)
                $last = end($events);
                if ($last && str_ends_with($compact['type'], '_delta') && $last['type'] === $compact['type']
                    && ($last['block_index'] ?? null) === ($compact['block_index'] ?? null)) {
                    $events[array_key_last($events)]['content'] .= $compact['content'] ?? '';
                    continue;
                }
                $events[] = $compact;
            }
            $next = $start + count($raw);
        } elseif ($from === 0) {
            $next = $total; // nothing live: the DB history is complete
        }

        return [
            'status' => $status,
            'streaming' => $streaming,
            'title' => $this->title((string) $task->prompt),
            'agent' => $task->agent?->name ?? 'Sub-agent',
            'duration_seconds' => max(0, (in_array($status, ['running', 'pending'], true) ? now() : ($this->finishedAt($task) ?? now()))->getTimestamp() - $task->created_at->getTimestamp()),
            'events' => $events,
            'next' => $next,
            'more' => $streaming && $next < $total,
        ];
    }

    /**
     * Saved messages as stream-shaped events (for turns that are no longer in Redis).
     */
    private function messageEvents(Conversation $conversation, bool $skipFirstUser): \Generator
    {
        $skipped = false;
        $messages = $conversation->messages()->reorder()->orderBy('sequence')->orderBy('id')->get(['id', 'role', 'content', 'sequence']);
        foreach ($messages as $message) {
            $content = $message->content;
            if ($message->role === 'user') {
                if (is_string($content) || (is_array($content) && collect($content)->contains(fn($b) => ($b['type'] ?? '') === 'text'))) {
                    if ($skipFirstUser && !$skipped) {
                        $skipped = true;
                        continue;
                    }
                    $text = is_string($content) ? $content : collect($content)->where('type', 'text')->pluck('text')->implode("\n\n");
                    yield ['type' => 'user', 'content' => Str::limit($text, 20000)];
                    continue;
                }
                foreach ((array) $content as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        yield [
                            'type' => 'tool_result',
                            'content' => $this->compactResult($block['content'] ?? ''),
                            'metadata' => ['tool_id' => $block['tool_use_id'] ?? null, 'is_error' => (bool) ($block['is_error'] ?? false)],
                        ];
                    }
                }
                continue;
            }
            if ($message->role !== 'assistant' || !is_array($content)) {
                continue;
            }
            foreach ($content as $i => $block) {
                $type = $block['type'] ?? '';
                $bi = 'm' . $message->id . '-' . $i;
                if ($type === 'thinking' && trim((string) ($block['thinking'] ?? '')) !== '') {
                    yield ['type' => 'thinking_start', 'block_index' => $bi];
                    yield ['type' => 'thinking_delta', 'block_index' => $bi, 'content' => (string) $block['thinking']];
                    yield ['type' => 'thinking_stop', 'block_index' => $bi];
                } elseif ($type === 'text') {
                    yield ['type' => 'text_start', 'block_index' => $bi];
                    yield ['type' => 'text_delta', 'block_index' => $bi, 'content' => (string) ($block['text'] ?? '')];
                    yield ['type' => 'text_stop', 'block_index' => $bi];
                } elseif ($type === 'tool_use') {
                    yield ['type' => 'tool_use_start', 'block_index' => $bi, 'metadata' => ['tool_id' => $block['id'] ?? null, 'tool_name' => $block['name'] ?? 'Tool']];
                    yield ['type' => 'tool_use_delta', 'block_index' => $bi, 'content' => json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
                    yield ['type' => 'tool_use_stop', 'block_index' => $bi];
                }
            }
        }
    }

    /**
     * Keep what the viewer needs; drop signatures/ids, shrink tool results.
     */
    private function compactEvent(array $event): ?array
    {
        $type = $event['type'] ?? '';
        if (in_array($type, ['thinking_signature', 'usage', 'tool_progress', 'ping', 'heartbeat'], true)) {
            return null;
        }
        $out = ['type' => $type];
        if (array_key_exists('block_index', $event)) {
            $out['block_index'] = $event['block_index'];
        }
        if ($type === 'tool_result') {
            $out['content'] = $this->compactResult($event['content'] ?? '');
            $out['metadata'] = ['tool_id' => $event['metadata']['tool_id'] ?? null, 'is_error' => (bool) ($event['metadata']['is_error'] ?? false)];
        } elseif ($type === 'tool_use_start') {
            $out['metadata'] = ['tool_id' => $event['metadata']['tool_id'] ?? null, 'tool_name' => $event['metadata']['tool_name'] ?? 'Tool'];
        } elseif (isset($event['content'])) {
            $out['content'] = is_string($event['content']) ? $event['content'] : json_encode($event['content']);
        }
        if ($type === 'error' || $type === 'done') {
            $out['metadata'] = array_intersect_key($event['metadata'] ?? [], array_flip(['stop_reason', 'message', 'error']));
        }

        return $out;
    }

    /**
     * Tool result as readable text: images replaced, long output cut.
     */
    private function compactResult(mixed $content): string
    {
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $content = $decoded;
            }
        }
        if (is_array($content)) {
            $content = collect($content)->map(function ($block) {
                if (!is_array($block)) {
                    return (string) $block;
                }
                return match ($block['type'] ?? '') {
                    'text' => (string) ($block['text'] ?? ''),
                    'image' => '[afbeelding]',
                    default => json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                };
            })->implode("\n");
        }
        $content = (string) $content;

        return mb_strlen($content) > 4000 ? mb_substr($content, 0, 4000) . "\n… (" . number_format(mb_strlen($content)) . ' tekens)' : $content;
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

        // Claude Code's own (native) sub-agents count for their chat as well
        foreach (app(NativeSubAgentMonitor::class)->runningByConversation() as $parentUuid => $count) {
            [$chatUuid, $sessionId] = $this->topChatOf((string) $parentUuid);
            if ($sessionId) {
                $sessions[$sessionId] = ($sessions[$sessionId] ?? 0) + $count;
                $conversations[$chatUuid] = ($conversations[$chatUuid] ?? 0) + $count;
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
