<?php

namespace App\Services;

use App\Models\Conversation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Claude Code's own sub-agents (the built-in Agent/Task tool), shown next to the
 * `pd subagent:run` sub-agents in the pill, sheet, badges and viewer.
 *
 * Data comes from Claude Code's transcripts via scripts/native-subagents.php, which
 * must run as appuser (the files are 0600). Status:
 * - completed: the transcript ended its turn
 * - running:   not finished and the parent chat is still processing
 * - cancelled: not finished and the parent chat stopped (native agents die with their turn)
 * PocketDev can't stop them, so they have no stop button.
 */
class NativeSubAgentMonitor
{
    private const FINISHED_WINDOW_MINUTES = 120;

    /**
     * Presented items (same shape as SubAgentMonitor::present) for these parent chats.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array>
     */
    public function forConversations(Collection $conversations): array
    {
        $bySession = $conversations
            ->filter(fn (Conversation $c) => $c->provider_type === 'claude_code' && $c->provider_session_id)
            ->keyBy('provider_session_id');
        if ($bySession->isEmpty()) {
            return [];
        }

        $items = [];
        foreach ($this->summaries($bySession->keys()->all()) as $agent) {
            $conversation = $bySession->get($agent['session_id']);
            if (!$conversation) {
                continue;
            }
            $item = $this->present($conversation, $agent);
            $finishedRecently = $item['finished_at'] && Carbon::parse($item['finished_at'])->gt(now()->subMinutes(self::FINISHED_WINDOW_MINUTES));
            if ($item['status'] === 'running' || $finishedRecently) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Running native sub-agents per chat (for the tab and session badges).
     *
     * @return array<string, int> conversation uuid => count
     */
    public function runningByConversation(): array
    {
        return Cache::remember('native_subagents_running', 5, function () {
            $processing = Conversation::where('provider_type', 'claude_code')
                ->where('status', Conversation::STATUS_PROCESSING)
                ->whereNotNull('provider_session_id')
                ->get(['id', 'uuid', 'status', 'provider_type', 'provider_session_id']);

            $counts = [];
            foreach ($this->forConversations($processing) as $item) {
                if ($item['status'] === 'running') {
                    $counts[$item['conversation_uuid']] = ($counts[$item['conversation_uuid']] ?? 0) + 1;
                }
            }

            return $counts;
        });
    }

    /**
     * Viewer payload (same shape as SubAgentMonitor::events).
     */
    public function events(Conversation $conversation, string $agentId, int $from): array
    {
        $agent = collect($this->summaries([(string) $conversation->provider_session_id]))
            ->firstWhere('agent_id', $agentId);
        $data = self::run(['events', (string) $conversation->provider_session_id, $agentId, (string) $from]) ?? [];
        $item = $agent ? $this->present($conversation, $agent) : null;
        $status = $item['status'] ?? 'cancelled';

        return [
            'status' => $status,
            'streaming' => $status === 'running',
            'title' => $item['title'] ?? 'Claude sub-agent',
            'agent' => $item['agent'] ?? 'Claude (ingebouwd)',
            'duration_seconds' => $item['duration_seconds'] ?? 0,
            'events' => $data['events'] ?? [],
            'next' => (int) ($data['next'] ?? $from),
            'more' => false,
        ];
    }

    private function present(Conversation $conversation, array $agent): array
    {
        $finished = (bool) $agent['finished'];
        $status = $finished ? 'completed'
            : ($conversation->status === Conversation::STATUS_PROCESSING ? 'running' : 'cancelled');
        $started = $agent['started_at'] ? Carbon::parse($agent['started_at']) : now();
        $lastAt = $agent['last_at'] ? Carbon::parse($agent['last_at']) : now();
        $end = $status === 'running' ? now() : $lastAt;

        $steps = [];
        if ($status === 'running') {
            foreach (array_values($agent['steps'] ?? []) as $i => $label) {
                $steps[] = ['label' => $label, 'done' => $i > 0];
            }
            if ($steps === []) {
                $steps[] = ['label' => 'Starten…', 'done' => false];
            }
        }

        return [
            'id' => 'native-' . $conversation->uuid . '-' . $agent['agent_id'],
            'native' => true,
            'events_url' => '/api/subagents/native/' . $conversation->uuid . '/' . $agent['agent_id'] . '/events',
            'conversation_uuid' => $conversation->uuid,
            'title' => Str::limit($agent['description'] !== '' ? $agent['description'] : 'Claude sub-agent', 80),
            'agent' => 'Claude (ingebouwd) · ' . $agent['agent_type'],
            'status' => $status,
            'background' => (bool) $agent['background'],
            'started_at' => $started->toIso8601String(),
            'finished_at' => $status === 'running' ? null : $lastAt->toIso8601String(),
            'duration_seconds' => max(0, $end->getTimestamp() - $started->getTimestamp()),
            'delivered' => false, // the result goes straight back to Claude, PocketDev doesn't track it
            'steps' => $steps,
            'error' => $status === 'cancelled' ? 'Gestopt toen de beurt van de hoofdchat eindigde.' : null,
        ];
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @return array<int, array>
     */
    private function summaries(array $sessionIds): array
    {
        return self::run(array_merge(['summary'], $sessionIds)) ?? [];
    }

    /**
     * Run scripts/native-subagents.php as appuser (directly in the queue container,
     * via `docker exec -u appuser` from php-fpm).
     */
    public static function run(array $args, int $timeoutSeconds = 15): ?array
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $isAppUser = function_exists('posix_geteuid') && @fileowner($home) === posix_geteuid();
        $argString = implode(' ', array_map('escapeshellarg', $args));

        if ($isAppUser) {
            $command = 'timeout ' . $timeoutSeconds . ' php ' . escapeshellarg(base_path('scripts/native-subagents.php')) . ' ' . $argString;
        } else {
            $container = config('pocketdev.project_name', 'pocket-dev') . '-queue';
            $command = 'timeout ' . $timeoutSeconds . ' docker exec -u appuser -e HOME=/home/appuser '
                . escapeshellarg($container) . ' php /var/www/scripts/native-subagents.php ' . $argString;
        }

        $output = shell_exec($command . ' 2>/dev/null');
        $decoded = is_string($output) ? json_decode($output, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
