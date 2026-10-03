<?php

namespace App\Services;

use App\Jobs\StartRemoteControl;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Providers\ClaudeCodeProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Claude Code Remote Control for PocketDev conversations.
 *
 * Runs an interactive `claude --remote-control` (in a pty via `script`) that
 * resumes the conversation's Claude session, so the same chat can be continued
 * from claude.ai/code or the Claude app.
 *
 * Process state lives in /tmp/pocketdev-rc/{uuid}/ because /tmp is shared by the
 * php and queue containers: PHP-FPM (www-data) only reads status and writes
 * flag files, the queue (appuser, owns the Claude OAuth login) runs the process.
 *
 * Files:
 *   enabled    toggle is on (user intent; survives PocketDev turns)
 *   heartbeat  touched every second by the wrapper while the CLI runs
 *   url        claude.ai/code session URL once connected
 *   stop       request a graceful stop (wrapper sends /exit, then kills)
 *   offset     JSONL byte offset at start; finalize imports what came after
 *   error      last error message shown in the UI
 *   imported   timestamp of the last import that added messages
 *
 * Only one writer per Claude session: before a PocketDev turn the process is
 * stopped (remote messages get imported), and restarted after the turn.
 */
class RemoteControlService
{
    public const BASE_DIR = '/tmp/pocketdev-rc';

    private const HEARTBEAT_STALE_SECONDS = 10;

    public function isSupported(Conversation $conversation): bool
    {
        return $conversation->provider_type === 'claude_code';
    }

    public function dir(Conversation|string $conversation): string
    {
        $uuid = $conversation instanceof Conversation ? $conversation->uuid : $conversation;

        return self::BASE_DIR . '/' . $uuid;
    }

    public function isEnabled(Conversation $conversation): bool
    {
        return is_file($this->dir($conversation) . '/enabled');
    }

    public function isRunning(Conversation|string $conversation): bool
    {
        $heartbeat = $this->dir($conversation) . '/heartbeat';
        clearstatcache(true, $heartbeat);

        return is_file($heartbeat) && (time() - filemtime($heartbeat)) <= self::HEARTBEAT_STALE_SECONDS;
    }

    public function status(Conversation $conversation): array
    {
        $dir = $this->dir($conversation);
        $running = $this->isRunning($conversation);
        $enabled = $this->isEnabled($conversation);
        $url = $running ? $this->readFile("{$dir}/url") : null;

        return [
            'supported' => $this->isSupported($conversation),
            'enabled' => $enabled,
            'running' => $running,
            // On, but PocketDev is working on this chat: restarts after the turn
            'paused' => $enabled && !$running && $this->isProcessing($conversation),
            'connected' => $running && $url !== null,
            'url' => $url,
            'name' => $this->sessionName($conversation),
            'error' => $this->readFile("{$dir}/error"),
            'imported_at' => $this->readFile("{$dir}/imported"),
        ];
    }

    /**
     * Self-heal while the UI polls: on, no process, chat idle, and no start
     * requested recently (e.g. a crash or a worker that missed the restart).
     * $force skips the 90s debounce (end of a PocketDev turn). Duplicate starts
     * are harmless: start() takes a lock and returns when already running.
     */
    public function ensureStarted(Conversation $conversation, bool $force = false): void
    {
        if (!$this->isSupported($conversation) || !$this->isEnabled($conversation)
            || $this->isRunning($conversation) || $this->isProcessing($conversation)) {
            return;
        }

        $marker = $this->dir($conversation) . '/dispatched';
        clearstatcache(true, $marker);
        if (!$force && is_file($marker) && time() - filemtime($marker) < 90) {
            return;
        }

        $this->markDispatched($conversation);
        StartRemoteControl::dispatch($conversation->uuid);
    }

    private function markDispatched(Conversation $conversation): void
    {
        $marker = $this->ensureDir($conversation) . '/dispatched';
        @touch($marker);
        @chmod($marker, 0666);
    }

    private function isProcessing(Conversation $conversation): bool
    {
        return Conversation::where('id', $conversation->id)->value('status') === Conversation::STATUS_PROCESSING;
    }

    /**
     * Toggle on (called from PHP-FPM). The queue job does the actual start.
     */
    public function enable(Conversation $conversation): void
    {
        $dir = $this->ensureDir($conversation);
        @unlink("{$dir}/error");
        file_put_contents("{$dir}/enabled", (string) time());
        @chmod("{$dir}/enabled", 0666);

        $this->markDispatched($conversation);
        StartRemoteControl::dispatch($conversation->uuid);
    }

    /**
     * Toggle off. The wrapper exits gracefully and imports remote messages.
     */
    public function disable(Conversation $conversation): void
    {
        $dir = $this->ensureDir($conversation);
        @unlink("{$dir}/enabled");
        @unlink("{$dir}/error");

        if ($this->isRunning($conversation)) {
            $this->requestStop($conversation);
        }
    }

    public function requestStop(Conversation $conversation): void
    {
        $dir = $this->ensureDir($conversation);
        file_put_contents("{$dir}/stop", (string) time());
        @chmod("{$dir}/stop", 0666);
    }

    /**
     * Stop the process before PocketDev writes to the same Claude session.
     * Waits for the wrapper to finish (incl. importing remote messages).
     */
    public function suspendForTurn(Conversation $conversation, int $timeoutSeconds = 30): bool
    {
        if (!$this->isSupported($conversation) || !is_dir($this->dir($conversation))) {
            return false;
        }

        // Same lock as start(): a start that is still building its command
        // finishes (and heartbeats) before we look, so we never miss it.
        try {
            return Cache::lock('remote-control:' . $conversation->uuid, 60)
                ->block(60, fn() => $this->stopAndWait($conversation, $timeoutSeconds));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            Log::warning('RemoteControl: lock timeout before turn', ['conversation' => $conversation->uuid]);
            return $this->stopAndWait($conversation, $timeoutSeconds);
        }
    }

    /**
     * Request a stop and wait until the wrapper finished (incl. import).
     */
    private function stopAndWait(Conversation $conversation, int $timeoutSeconds): bool
    {
        if (!$this->isRunning($conversation)) {
            return false;
        }

        $dir = $this->dir($conversation);
        $this->requestStop($conversation);

        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (!$this->isRunning($conversation)) {
                return true;
            }
            usleep(500_000);
        }

        Log::warning('RemoteControl: process did not stop in time', [
            'conversation' => $conversation->uuid,
        ]);

        return true;
    }

    /**
     * Start the Remote Control process. Must run in the queue container (appuser).
     */
    public function start(
        Conversation $conversation,
        SystemPromptBuilder $systemPromptBuilder,
        ToolRegistry $toolRegistry,
        ProviderFactory $providerFactory
    ): void {
        if (!$this->isSupported($conversation) || !$this->isEnabled($conversation)) {
            return;
        }

        $lock = Cache::lock('remote-control:' . $conversation->uuid, 60);
        if (!$lock->get()) {
            return; // Another start is in progress
        }

        try {
            $dir = $this->ensureDir($conversation);
            if ($this->isRunning($conversation)) {
                if (!is_file("{$dir}/stop")) {
                    return; // Already running
                }
                // Toggled off and on quickly: let the old process finish its import
                $this->stopAndWait($conversation, 30);
                if ($this->isRunning($conversation)) {
                    throw new \RuntimeException('de vorige sessie stopt niet');
                }
            }

            // A PocketDev turn is running; the stream job restarts us when it ends.
            if ($conversation->fresh()->status === Conversation::STATUS_PROCESSING) {
                return;
            }

            $provider = $providerFactory->make('claude_code');
            if (!$provider instanceof ClaudeCodeProvider) {
                throw new \RuntimeException('Claude Code provider not available');
            }

            foreach (['stop', 'stopped', 'url', 'out', 'error', 'heartbeat'] as $file) {
                @unlink("{$dir}/{$file}");
            }

            $workingDir = $conversation->working_directory ?: '/workspace';
            $this->ensureCliReady($workingDir);

            // Resume the existing Claude session, or create one with a fixed ID
            $sessionId = $conversation->provider_session_id;
            $resume = !empty($sessionId) && is_file($this->sessionFilePath($workingDir, $sessionId));
            if (!$resume) {
                $sessionId = $sessionId ?: (string) Str::uuid();
                $conversation->provider_session_id = $sessionId;
                $conversation->save();
            }

            $sessionFile = $this->sessionFilePath($workingDir, $sessionId);
            clearstatcache(true, $sessionFile);
            file_put_contents("{$dir}/offset", is_file($sessionFile) ? (string) filesize($sessionFile) : '0');

            $systemPrompt = $systemPromptBuilder->build(
                $conversation,
                $toolRegistry,
                $provider->getSystemPromptType(),
                $provider->getProviderType()
            );
            file_put_contents("{$dir}/system-prompt.md", $systemPrompt);

            // PocketDev's user settings + skip the bypass-permissions confirmation
            // (no one can click "Yes, I accept" in a headless pty)
            $home = getenv('HOME') ?: '/home/appuser';
            $settings = json_decode((string) @file_get_contents("{$home}/.claude/settings.json"));
            $settings = $settings instanceof \stdClass ? $settings : new \stdClass();
            $settings->skipDangerousModePermissionPrompt = true;
            file_put_contents("{$dir}/settings.json", json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $command = $provider->buildRemoteControlCommand(
                $conversation,
                $this->sessionName($conversation),
                "{$dir}/system-prompt.md",
                $sessionId,
                $resume,
                "{$dir}/settings.json"
            );

            // Wide pty so the session URL is printed on one line
            file_put_contents("{$dir}/run.sh", implode("\n", [
                '#!/bin/bash',
                'stty cols 400 rows 60 2>/dev/null',
                'cd ' . escapeshellarg($workingDir) . ' || exit 1',
                'exec ' . $command,
                '',
            ]));
            file_put_contents("{$dir}/wrapper.sh", $this->wrapperScript());

            $env = $provider->buildRemoteControlEnvironment($conversation);
            $env['TERM'] = 'xterm-256color';
            // Markers inherited from a parent Claude session would turn off
            // transcript saving, which breaks --resume and the message import.
            foreach ([
                'CLAUDECODE', 'CLAUDE_PID', 'CLAUDE_CODE_CHILD_SESSION', 'CLAUDE_CODE_SESSION_ID',
                'CLAUDE_CODE_SESSION_ATTENDED', 'CLAUDE_CODE_ENTRYPOINT', 'CLAUDE_CODE_EXECPATH',
                'CLAUDE_CODE_SSE_PORT', 'CLAUDE_CODE_MESSAGING_SOCKET', 'CLAUDE_CODE_MESSAGING_TOKEN',
            ] as $key) {
                unset($env[$key]);
            }

            // Count as running from now on so a concurrent turn waits for us
            touch("{$dir}/heartbeat");

            $process = proc_open(
                ['setsid', '-f', 'bash', "{$dir}/wrapper.sh", $dir, $conversation->uuid, base_path('artisan')],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/wrapper.log", 'a'], 2 => ['file', "{$dir}/wrapper.log", 'a']],
                $pipes,
                $dir,
                $env
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start the Remote Control process');
            }
            proc_close($process);

            Log::info('RemoteControl: started', [
                'conversation' => $conversation->uuid,
                'session_id' => $sessionId,
                'resume' => $resume,
            ]);
        } catch (\Throwable $e) {
            Log::error('RemoteControl: start failed', [
                'conversation' => $conversation->uuid,
                'error' => $e->getMessage(),
            ]);
            $this->writeError($conversation, 'Remote Control starten mislukt: ' . $e->getMessage());
            @unlink($this->dir($conversation) . '/enabled');
        } finally {
            $lock->release();
        }
    }

    /**
     * Called by the wrapper after the CLI exited (artisan remote-control:finalize).
     * Imports messages sent from claude.ai / the Claude app into PocketDev.
     */
    public function finalize(Conversation $conversation): int
    {
        $dir = $this->dir($conversation);
        $stopRequested = is_file("{$dir}/stop");
        $imported = 0;

        try {
            $imported = $this->importRemoteMessages($conversation);
        } catch (\Throwable $e) {
            Log::error('RemoteControl: import failed', [
                'conversation' => $conversation->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        $workingDir = $conversation->working_directory ?: '/workspace';
        $sessionId = $conversation->provider_session_id;
        if ($sessionId && !is_file($this->sessionFilePath($workingDir, $sessionId))) {
            // Fresh session that never got a message: don't let PocketDev --resume a missing file
            $conversation->provider_session_id = null;
            $conversation->save();
        }

        if (!$stopRequested && $this->isEnabled($conversation)) {
            // Exited on its own (ended from the app, auth error, crash): toggle off
            @unlink("{$dir}/enabled");
            $reason = $this->lastOutputError($dir);
            $this->writeError($conversation, $reason ?: 'Remote Control-sessie is beëindigd.');
        }

        return $imported;
    }

    /**
     * Turn the JSONL entries written after `offset` into PocketDev messages.
     */
    public function importRemoteMessages(Conversation $conversation): int
    {
        $dir = $this->dir($conversation);
        $workingDir = $conversation->working_directory ?: '/workspace';
        $sessionId = $conversation->provider_session_id;
        if (!$sessionId) {
            return 0;
        }

        $sessionFile = $this->sessionFilePath($workingDir, $sessionId);
        if (!is_file($sessionFile)) {
            return 0;
        }

        $offset = (int) ($this->readFile("{$dir}/offset") ?? 0);
        clearstatcache(true, $sessionFile);
        $size = filesize($sessionFile);
        if ($size <= $offset) {
            return 0;
        }

        $handle = fopen($sessionFile, 'r');
        fseek($handle, $offset);
        $raw = stream_get_contents($handle);
        fclose($handle);

        // Next import (e.g. after a restart) continues from here
        file_put_contents("{$dir}/offset", (string) $size);

        $messages = $this->parseSessionEntries(explode("\n", $raw));
        foreach ($messages as $message) {
            Message::create(['conversation_id' => $conversation->id] + $message);
            if ($message['role'] === Message::ROLE_ASSISTANT) {
                $conversation->addTokenUsage($message['input_tokens'] ?? 0, $message['output_tokens'] ?? 0);
            }
        }

        if (!empty($messages)) {
            app(ConversationTurnCalculator::class)->store($conversation);
            $conversation->touch();
            file_put_contents("{$dir}/imported", (string) time());
            @chmod("{$dir}/imported", 0666);
        }

        return count($messages);
    }

    /**
     * Map Claude Code JSONL lines to PocketDev message attributes.
     *
     * @param string[] $lines
     * @return array<int, array<string, mixed>>
     */
    public function parseSessionEntries(array $lines): array
    {
        $messages = [];
        $assistant = null; // Assistant lines with the same message.id form one message

        $flushAssistant = function () use (&$assistant, &$messages) {
            if ($assistant !== null && !empty($assistant['content'])) {
                $messages[] = $assistant;
            }
            $assistant = null;
        };

        foreach ($lines as $line) {
            $entry = json_decode(trim($line), true);
            if (!is_array($entry) || !empty($entry['isSidechain']) || !empty($entry['isMeta'])
                || !empty($entry['isCompactSummary']) || !empty($entry['isVisibleInTranscriptOnly'])) {
                continue;
            }

            $type = $entry['type'] ?? null;
            $message = $entry['message'] ?? null;
            if (!in_array($type, ['user', 'assistant'], true) || !is_array($message)) {
                continue;
            }

            if ($type === 'assistant') {
                if (($message['model'] ?? null) === '<synthetic>') {
                    continue;
                }
                $id = $message['id'] ?? $entry['uuid'] ?? null;
                if ($assistant === null || $assistant['_id'] !== $id) {
                    $flushAssistant();
                    $assistant = [
                        '_id' => $id,
                        'role' => Message::ROLE_ASSISTANT,
                        'content' => [],
                        'model' => $message['model'] ?? null,
                    ];
                }
                foreach ((array) ($message['content'] ?? []) as $block) {
                    if (!is_array($block) || !in_array($block['type'] ?? null, ['text', 'thinking', 'tool_use'], true)) {
                        continue;
                    }
                    // Redacted/empty thinking only carries a signature: nothing to show
                    if ($block['type'] === 'thinking' && trim((string) ($block['thinking'] ?? '')) === '') {
                        continue;
                    }
                    $assistant['content'][] = $block;
                }
                $usage = $message['usage'] ?? [];
                $assistant['input_tokens'] = (int) ($usage['input_tokens'] ?? 0);
                $assistant['output_tokens'] = (int) ($usage['output_tokens'] ?? 0);
                $assistant['cache_creation_tokens'] = (int) ($usage['cache_creation_input_tokens'] ?? 0);
                $assistant['cache_read_tokens'] = (int) ($usage['cache_read_input_tokens'] ?? 0);
                $assistant['stop_reason'] = $message['stop_reason'] ?? null;
                continue;
            }

            // User entry
            $flushAssistant();
            $content = $message['content'] ?? null;

            if (is_string($content)) {
                if ($this->isCliNoise($content)) {
                    continue;
                }
                $messages[] = ['role' => Message::ROLE_USER, 'content' => $content];
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            $toolResults = array_values(array_filter($content, fn($b) => ($b['type'] ?? null) === 'tool_result'));
            if (!empty($toolResults)) {
                $messages[] = ['role' => Message::ROLE_USER, 'content' => array_map(fn($b) => [
                    'type' => 'tool_result',
                    'tool_use_id' => $b['tool_use_id'] ?? null,
                    'content' => $b['content'] ?? '',
                    'is_error' => (bool) ($b['is_error'] ?? false),
                ], $toolResults)];
                continue;
            }

            $text = collect($content)
                ->filter(fn($b) => ($b['type'] ?? null) === 'text')
                ->pluck('text')
                ->implode("\n\n");
            if (trim($text) !== '' && !$this->isCliNoise($text)) {
                $messages[] = ['role' => Message::ROLE_USER, 'content' => $text];
            }
        }

        $flushAssistant();

        return array_map(function ($m) {
            unset($m['_id']);
            return $m;
        }, $messages);
    }

    /**
     * Name shown in the Claude app: "PocketDev · {session} · {tab}".
     * A session has several chat tabs, so the tab part keeps names unique:
     * the tab label if set, else "#{chat number}" plus the chat title.
     */
    public function sessionName(Conversation $conversation): string
    {
        $clean = fn(?string $text, int $max) => Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $text)), $max, '…');

        $screen = $conversation->screen;
        $session = $clean($screen?->session?->name, 30) ?: 'Sessie';

        $tab = $clean($conversation->tab_label, 30);
        if ($tab === '') {
            $tab = '#' . ($screen?->chat_number ?: '?');
            $title = $clean($conversation->title, 30);
            if ($title !== '' && $title !== 'New Chat' && $title !== $session) {
                $tab .= ' ' . $title;
            }
        }

        return "PocketDev · {$session} · {$tab}";
    }

    private function isCliNoise(string $text): bool
    {
        $trimmed = ltrim($text);

        return str_starts_with($trimmed, '<command-')
            || str_starts_with($trimmed, '<local-command')
            || str_starts_with($trimmed, '[Request interrupted');
    }

    /**
     * Interactive mode needs workspace trust, the RC consent and onboarding done;
     * without a terminal to answer, the CLI would otherwise stop at a prompt.
     */
    private function ensureCliReady(string $workingDir): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $configFile = $home . '/.claude.json';

        $handle = @fopen($configFile, 'c+');
        if (!$handle) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            // Objects (not arrays) so empty {} entries stay {} when re-encoded
            $config = $raw === '' ? new \stdClass() : json_decode($raw);
            if (!$config instanceof \stdClass) {
                return; // Unreadable/corrupt: leave the CLI's config alone
            }

            $changed = false;
            foreach (['hasCompletedOnboarding', 'remoteDialogSeen'] as $flag) {
                if (empty($config->{$flag})) {
                    $config->{$flag} = true;
                    $changed = true;
                }
            }
            if (!isset($config->projects) || !$config->projects instanceof \stdClass) {
                $config->projects = new \stdClass();
            }
            if (!isset($config->projects->{$workingDir}) || !$config->projects->{$workingDir} instanceof \stdClass) {
                $config->projects->{$workingDir} = new \stdClass();
            }
            if (empty($config->projects->{$workingDir}->hasTrustDialogAccepted)) {
                $config->projects->{$workingDir}->hasTrustDialogAccepted = true;
                $changed = true;
            }

            if ($changed) {
                // Rewrite in place: keeps owner/group/mode the CLI and PHP-FPM rely on
                $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $json);
                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function sessionFilePath(string $workingDir, string $sessionId): string
    {
        $home = getenv('HOME') ?: '/home/appuser';
        // Claude CLI encodes paths by replacing both '/' and '_' with '-'
        $encodedPath = str_replace(['/', '_'], '-', $workingDir);

        return "{$home}/.claude/projects/{$encodedPath}/{$sessionId}.jsonl";
    }

    private function ensureDir(Conversation $conversation): string
    {
        if (!is_dir(self::BASE_DIR)) {
            @mkdir(self::BASE_DIR, 0777, true);
            @chmod(self::BASE_DIR, 0777);
        }
        $dir = $this->dir($conversation);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // Both www-data (php) and appuser (queue) write flag files here
        @chmod($dir, 0777);

        return $dir;
    }

    private function writeError(Conversation $conversation, string $message): void
    {
        $dir = $this->ensureDir($conversation);
        file_put_contents("{$dir}/error", $message);
        @chmod("{$dir}/error", 0666);
    }

    private function lastOutputError(string $dir): ?string
    {
        $out = $this->readFile("{$dir}/out");
        if ($out === null) {
            return null;
        }
        foreach (array_reverse(explode("\n", $out)) as $line) {
            if (!str_starts_with($line, 'https://')) {
                return trim($line);
            }
        }

        return null;
    }

    private function readFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = trim((string) @file_get_contents($path));

        return $content === '' ? null : $content;
    }

    /**
     * Bash supervisor: runs the CLI in a pty, keeps a heartbeat, captures the
     * session URL, handles graceful stop and imports remote messages on exit.
     */
    private function wrapperScript(): string
    {
        return <<<'BASH'
#!/bin/bash
# Args: <state dir> <conversation uuid> <artisan path>
DIR="$1"; UUID="$2"; ARTISAN="$3"
umask 000
cd "$DIR" || exit 1

# Don't keep the queue worker's sockets/log handles open for hours
for FD in /proc/$$/fd/*; do
  FD=${FD##*/}
  [ "$FD" -gt 2 ] && [ "$FD" -ne 255 ] && eval "exec $FD>&-" 2>/dev/null
done

rm -f in script.pid
mkfifo in
exec 3<>in

( echo $BASHPID > script.pid; exec script -qfec "bash '$DIR/run.sh'" /dev/null <&3 ) \
  | stdbuf -o0 tr '\r\033' '\n\n' \
  | grep -aoE --line-buffered 'https://claude\.ai/code/session_[A-Za-z0-9]+|(Error|error):.{0,200}|Remote Control requires.{0,160}|not (yet )?enabled.{0,120}' \
  > out &
PIPE=$!

STOPPING=0
DEADLINE=0
while kill -0 "$PIPE" 2>/dev/null; do
  touch heartbeat
  if [ ! -s url ]; then
    U=$(grep -m1 -aoE 'https://claude\.ai/code/session_[A-Za-z0-9]+' out 2>/dev/null)
    [ -n "$U" ] && echo "$U" > url
  fi
  if [ -f stop ] && [ "$STOPPING" -eq 0 ]; then
    STOPPING=1
    printf '/exit\r' >&3
    DEADLINE=$(( $(date +%s) + 12 ))
  fi
  if [ "$STOPPING" -eq 1 ] && [ "$(date +%s)" -ge "$DEADLINE" ]; then
    SPID=$(cat script.pid 2>/dev/null)
    if [ -n "$SPID" ]; then
      pkill -TERM -P "$SPID" 2>/dev/null
      kill -TERM "$SPID" 2>/dev/null
    fi
    STOPPING=2
  fi
  sleep 1
done

exec 3>&-
rm -f in script.pid url
# Still "running" while importing, so a new turn/start waits for the import
touch heartbeat
php "$ARTISAN" remote-control:finalize "$UUID" >> finalize.log 2>&1
rm -f heartbeat
touch stopped
BASH;
    }
}
