<?php

namespace App\Services\Providers;

use App\Models\Conversation;
use App\Models\Credential;
use App\Models\Message;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenCode CLI provider (OpenCode Go subscription).
 *
 * Runs `opencode run` (OpenCode V2, npm @opencode/cli) with JSON events.
 * Login is done by the user with `opencode auth login opencode`; an
 * OPENCODE_API_KEY credential works as well. Every Go model is usable because
 * the CLI talks to all Go endpoints (OpenAI, Anthropic and Google style).
 *
 * CLI mapping:
 *   prompt                 => stdin
 *   --model                => -m opencode-go/<model>[#<variant>]
 *   --resume               => -s <sessionID>
 *   permissions            => --auto
 *   --system-prompt        => OPENCODE_CONFIG_CONTENT {"instructions": [file]}
 *
 * Event stream (one JSON object per line):
 *   step_start, text, reasoning, tool_use (completed or error), step_finish (tokens + cost), error
 *
 * "Contributor" models (e.g. muse-spark-1.3-contributor) are discounted because the
 * model vendor may train on your prompts. They are flagged everywhere in the UI.
 */
class OpenCodeProvider extends AbstractCliProvider
{
    /** OpenCode provider id of the Go subscription (models are opencode-go/<id>). */
    public const GO_PROVIDER = 'opencode-go';

    private const INSTRUCTIONS_DIR = '/tmp/pocketdev-opencode';

    /** Labels for the CLI variant ids (the set per model comes from `opencode api model.list`). */
    public const VARIANT_LABELS = [
        'default' => 'Model default',
        'none' => 'Uit (geen reasoning)',
        'minimal' => 'Minimaal',
        'low' => 'Laag',
        'medium' => 'Gemiddeld',
        'high' => 'Hoog',
        'xhigh' => 'Extra hoog',
        'max' => 'Maximaal',
        'thinking' => 'Aan (thinking)',
    ];

    private ?string $instructionsFile = null;

    /**
     * Session totals before this run. ProcessConversationStream expects CLI providers to
     * report session-cumulative tokens (it stores deltas); `opencode run` reports per run.
     */
    private array $baseTotals = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];

    public function __construct(ModelRepository $models)
    {
        parent::__construct($models);
    }

    public function getProviderType(): string
    {
        return 'opencode';
    }

    /**
     * Ensure the instructions file is removed on success, abort, and error paths.
     */
    public function streamMessage(Conversation $conversation, array $options = []): Generator
    {
        try {
            yield from parent::streamMessage($conversation, $options);
        } finally {
            if ($this->instructionsFile !== null) {
                @unlink($this->instructionsFile);
                $this->instructionsFile = null;
            }
        }
    }

    // ========================================================================
    // Session
    // ========================================================================

    public function getSessionId(Conversation $conversation): ?string
    {
        return $conversation->provider_session_id;
    }

    public function setSessionId(Conversation $conversation, string $sessionId): void
    {
        $conversation->provider_session_id = $sessionId;
    }

    // ========================================================================
    // Binary + authentication
    // ========================================================================

    public static function binaryPath(): ?string
    {
        $home = getenv('HOME') ?: '/home/appuser';
        foreach ([$home . '/.npm-global/bin/opencode', $home . '/.opencode/bin/opencode', $home . '/.local/bin/opencode'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec('command -v opencode 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    /**
     * Run an opencode CLI command as appuser (owner of ~/.local/share/opencode).
     * The queue worker already is appuser; web requests (www-data in the php
     * container) go through `docker exec -u appuser` into the queue container so
     * they never touch the CLI database with the wrong user.
     */
    public static function runCli(string $args, int $timeoutSeconds = 30, array $env = []): string
    {
        $envPrefix = '';
        foreach ($env as $key => $value) {
            $envPrefix .= $key . '=' . escapeshellarg((string) $value) . ' ';
        }

        $home = getenv('HOME') ?: '/home/appuser';
        $isAppUser = function_exists('posix_geteuid') && @fileowner($home) === posix_geteuid();

        if ($isAppUser) {
            $bin = self::binaryPath();
            if ($bin === null) {
                return '';
            }
            $command = $envPrefix . 'timeout ' . $timeoutSeconds . ' ' . escapeshellarg($bin) . ' ' . $args;
        } else {
            $container = config('pocketdev.project_name', 'pocket-dev') . '-queue';
            $command = 'timeout ' . ($timeoutSeconds + 5) . ' docker exec -u appuser -e HOME=/home/appuser '
                . escapeshellarg($container) . ' sh -lc '
                . escapeshellarg('B=$(command -v opencode || echo $HOME/.npm-global/bin/opencode); ' . $envPrefix . 'timeout ' . $timeoutSeconds . ' "$B" ' . $args);
        }

        $output = (string) shell_exec($command . ' 2>/dev/null');

        return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $output) ?? $output;
    }

    /**
     * Logged in when `opencode auth list` shows a saved OpenCode account,
     * or when an OPENCODE_API_KEY credential exists. Cached for 60 seconds.
     */
    public static function isLoggedIn(bool $fresh = false): bool
    {
        if ($fresh) {
            Cache::forget('opencode_logged_in');
        }

        return Cache::remember('opencode_logged_in', 60, function () {
            if (Credential::findByEnvVar('OPENCODE_API_KEY') !== null) {
                return true;
            }

            return self::authStatus()['logged_in'];
        });
    }

    /**
     * Raw login status for the setup page.
     *
     * @return array{installed: bool, logged_in: bool, accounts: array<int, string>, version: ?string}
     */
    public static function authStatus(): array
    {
        $version = trim(self::runCli('--version', 15));
        if ($version === '') {
            return ['installed' => false, 'logged_in' => false, 'accounts' => [], 'version' => null];
        }

        $accounts = [];
        foreach (preg_split('/\R/', self::runCli('auth list')) as $line) {
            $line = trim($line);
            // e.g. "OpenCode   you@example.com   account" (environment-only rows are ignored)
            if ($line !== '' && stripos($line, 'opencode') === 0 && !str_ends_with(strtolower($line), 'environment')) {
                $accounts[] = preg_replace('/\s{2,}/', ' · ', $line);
            }
        }

        return [
            'installed' => true,
            'logged_in' => !empty($accounts),
            'accounts' => $accounts,
            'version' => $version,
        ];
    }

    public function hasCredentials(): bool
    {
        return self::isLoggedIn();
    }

    public function isAuthenticated(): bool
    {
        return self::isLoggedIn();
    }

    protected function isCliBinaryAvailable(): bool
    {
        // The CLI runs in the queue container (as appuser); the shared home makes it visible here too
        return self::binaryPath() !== null || is_link((getenv('HOME') ?: '/home/appuser') . '/.npm-global/bin/opencode');
    }

    protected function hasAuthCredentials(): bool
    {
        return self::isLoggedIn();
    }

    protected function getAuthRequiredError(): string
    {
        return 'OPENCODE_AUTH_REQUIRED:OpenCode is not logged in. Run `opencode auth login opencode` in a terminal (see Settings → Credentials → OpenCode Go).';
    }

    // ========================================================================
    // Models
    // ========================================================================

    public function getModels(): array
    {
        return collect(self::discoverModels())
            ->mapWithKeys(fn (array $m) => [
                $m['model_id'] => [
                    'name' => $m['display_name'],
                    'context_window' => $m['context_window'],
                    'max_output_tokens' => $m['max_output_tokens'],
                    'contributor' => $m['contributor'],
                ],
            ])
            ->toArray();
    }

    public function getContextWindow(string $model): int
    {
        foreach (self::discoverModels() as $m) {
            if ($m['model_id'] === $model) {
                return (int) $m['context_window'];
            }
        }

        return 200000;
    }

    /**
     * OpenCode Go models from the CLI itself (`opencode api model.list`): names, limits
     * and the exact variants (reasoning levels) the CLI accepts per model. Before login the
     * CLI only lists Go models with a key present, so a placeholder key is used for
     * listing (no request is sent to a model). Fallback: models.dev without variants, so
     * the UI can never offer a variant the CLI would reject. Cached for 1 hour.
     *
     * @return array<int, array{model_id: string, display_name: string, context_window: int, max_output_tokens: int, max_context_window: int, contributor: bool, efforts: array<int, string>}>
     */
    public static function discoverModels(): array
    {
        // Logged in, the CLI uses the account's model config (other variants than the
        // public list, e.g. no variants for Qwen/MiniMax): cache both states separately.
        $loggedIn = self::isLoggedIn();
        $cacheKey = 'opencode_go_models' . ($loggedIn ? '' : '_anonymous');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $entries = [];
        $env = $loggedIn ? [] : ['OPENCODE_API_KEY' => 'pocketdev-model-listing'];
        $list = json_decode(self::runCli('api --standalone model.list', 45, $env), true);
        foreach ((array) ($list['data'] ?? []) as $m) {
            if (($m['providerID'] ?? '') !== self::GO_PROVIDER || empty($m['modelID']) || ($m['enabled'] ?? true) === false) {
                continue;
            }
            $entries[$m['modelID']] = [
                'name' => $m['name'] ?? $m['modelID'],
                'limit' => $m['limit'] ?? [],
                'efforts' => array_values(array_filter(array_map(
                    fn ($v) => is_array($v) ? (string) ($v['id'] ?? '') : (string) $v,
                    (array) ($m['variants'] ?? [])
                ))),
            ];
        }

        if (empty($entries)) {
            // CLI unavailable: names + limits from models.dev, no variants (only "model default")
            foreach (self::modelsDevGoModels() as $id => $m) {
                $entries[$id] = ['name' => $m['name'] ?? $id, 'limit' => $m['limit'] ?? [], 'efforts' => []];
            }
        }

        $models = [];
        foreach ($entries as $id => $m) {
            $name = $m['name'];
            $contributor = OpenAICompatibleProvider::isContributorModel($id . ' ' . $name);
            $context = (int) ($m['limit']['context'] ?? 200000);
            $models[] = [
                'model_id' => (string) $id,
                'display_name' => $contributor ? '⚠ CONTRIBUTOR (traint op je data) · ' . $name : $name,
                'context_window' => $context,
                'max_context_window' => $context,
                'max_output_tokens' => (int) ($m['limit']['output'] ?? 32000),
                'contributor' => $contributor,
                'efforts' => $m['efforts'],
            ];
        }

        // Regular models first (alphabetical), contributor models last
        usort($models, fn ($a, $b) => [$a['contributor'], strtolower($a['display_name'])]
            <=> [$b['contributor'], strtolower($b['display_name'])]);

        Cache::put($cacheKey, $models, empty($models) ? 300 : 3600);

        return $models;
    }

    /**
     * Variants (reasoning levels) the CLI accepts for this model.
     *
     * @return array<int, string>
     */
    public static function variantsFor(string $model): array
    {
        foreach (self::discoverModels() as $m) {
            if ($m['model_id'] === $model) {
                return $m['efforts'];
            }
        }

        return [];
    }

    /**
     * models.dev entries of the opencode-go provider (only the fields we use).
     */
    private static function modelsDevGoModels(): array
    {
        $cached = Cache::get('models_dev_opencode_go');
        if (is_array($cached)) {
            return $cached;
        }

        $models = [];
        try {
            $response = Http::timeout(15)->get('https://models.dev/api.json');
            $provider = $response->successful() ? ($response->json(self::GO_PROVIDER) ?? []) : [];
            foreach ((array) ($provider['models'] ?? []) as $id => $m) {
                $models[$id] = [
                    'name' => $m['name'] ?? $id,
                    'limit' => $m['limit'] ?? [],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('OpenCodeProvider: models.dev lookup failed', ['error' => $e->getMessage()]);
        }

        Cache::put('models_dev_opencode_go', $models, empty($models) ? 300 : 21600);

        return $models;
    }

    /**
     * Clamp the requested effort to a variant this model supports (null = model default).
     */
    private function resolveVariant(string $model, ?string $effort): ?string
    {
        if ($effort === null || $effort === '' || $effort === 'default') {
            return null;
        }

        return in_array($effort, self::variantsFor($model), true) ? $effort : null;
    }

    // ========================================================================
    // Process
    // ========================================================================

    protected function buildCliCommand(Conversation $conversation, array $options): string
    {
        $this->baseTotals = !empty($this->getSessionId($conversation)) ? [
            'input' => (int) ($conversation->last_reported_input_tokens ?? 0),
            'output' => (int) ($conversation->last_reported_output_tokens ?? 0),
            'cache_read' => (int) ($conversation->last_reported_cache_read ?? 0),
            'cache_creation' => (int) ($conversation->last_reported_cache_creation ?? 0),
        ] : ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];

        $model = $conversation->model ?: 'kimi-k2.7-code';
        $effort = $conversation->getReasoningConfig()['effort'] ?? null;
        $variant = $this->resolveVariant($model, $effort);
        // "provider/model" (manual override, e.g. opencode/<free zen model>) is passed as-is
        $modelArg = (str_contains($model, '/') ? $model : self::GO_PROVIDER . '/' . $model)
            . ($variant !== null ? '#' . $variant : '');

        // PocketDev system prompt (tools, memory, skills) as an instructions file
        if (!empty($options['system'])) {
            if (!is_dir(self::INSTRUCTIONS_DIR)) {
                @mkdir(self::INSTRUCTIONS_DIR, 0775, true);
            }
            $this->instructionsFile = self::INSTRUCTIONS_DIR . '/conversation-' . $conversation->id . '-' . bin2hex(random_bytes(4)) . '.md';
            file_put_contents($this->instructionsFile, $options['system']);
        }

        $parts = [
            escapeshellarg(self::binaryPath() ?? 'opencode'),
            'run',
            '--standalone',
            '--format', 'json',
            '--thinking',
            '--auto',
            '-m', escapeshellarg($modelArg),
        ];

        $sessionId = $this->getSessionId($conversation);
        if (!empty($sessionId)) {
            $parts[] = '-s';
            $parts[] = escapeshellarg($sessionId);
        }

        return implode(' ', $parts);
    }

    protected function prepareProcessInput(string $command, string $userMessage): array
    {
        return ['command' => $command, 'stdin' => $userMessage];
    }

    protected function buildEnvironment(Conversation $conversation, array $options): array
    {
        $env = parent::buildEnvironment($conversation, $options);

        $config = [
            'autoupdate' => false,
            'share' => 'disabled',
        ];
        if ($this->instructionsFile !== null) {
            $config['instructions'] = [$this->instructionsFile];
        }
        $env['OPENCODE_CONFIG_CONTENT'] = json_encode($config, JSON_UNESCAPED_SLASHES);

        return $env;
    }

    // ========================================================================
    // Parsing
    // ========================================================================

    protected function initParseState(): array
    {
        return [
            'blockIndex' => 0,
            'sessionId' => null,
            'totalCost' => null,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'contextInputTokens' => 0,
            'cacheReadTokens' => 0,
            'cacheWriteTokens' => 0,
        ];
    }

    protected function getSessionIdFromState(array $state): ?string
    {
        return $state['sessionId'];
    }

    protected function classifyEventForTimeout(array $parsedData): array
    {
        $phase = match ($parsedData['type'] ?? '') {
            'step_start' => 'pending_response',
            'text', 'reasoning' => 'streaming',
            'tool_use' => 'pending_response',
            'step_finish' => 'streaming',
            default => null,
        };

        return ['phase' => $phase, 'resetsTimer' => true, 'shouldSkip' => false];
    }

    protected function closeOpenBlocks(array $state): Generator
    {
        // Every block is opened and closed within its own event
        return;
        yield;
    }

    protected function emitUsage(array $state): Generator
    {
        $state = $this->usageFromSessionExport($state);

        if ($state['totalCost'] !== null || $state['inputTokens'] > 0) {
            $base = $this->baseTotals;
            $cacheWrite = $base['cache_creation'] + $state['cacheWriteTokens'];
            $cacheRead = $base['cache_read'] + $state['cacheReadTokens'];

            yield StreamEvent::usage(
                $base['input'] + $state['inputTokens'],
                $base['output'] + $state['outputTokens'],
                $cacheWrite > 0 ? $cacheWrite : null,
                $cacheRead > 0 ? $cacheRead : null,
                $state['totalCost'],
                null,
                // Context fill: prompt of the last step + this run's output
                $state['contextInputTokens'] > 0 ? $state['contextInputTokens'] : null,
                $state['outputTokens'] > 0 ? $state['outputTokens'] : null
            );
        }
    }

    /**
     * `opencode run` emits no step_finish for the final step, so its tokens are missing
     * from the stream. The session export (~0.1s) has exact per-message usage: sum the
     * assistant messages after the last user message (= this run).
     */
    private function usageFromSessionExport(array $state): array
    {
        if (empty($state['sessionId'])) {
            return $state;
        }

        try {
            $export = json_decode(self::runCli('session export ' . escapeshellarg($state['sessionId']), 20), true);
            $messages = is_array($export['messages'] ?? null) ? $export['messages'] : [];

            $lastUser = -1;
            foreach ($messages as $i => $message) {
                if (($message['type'] ?? '') === 'user') {
                    $lastUser = $i;
                }
            }

            $run = ['inputTokens' => 0, 'outputTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'contextInputTokens' => 0, 'totalCost' => null];
            $found = false;
            foreach (array_slice($messages, $lastUser + 1) as $message) {
                if (($message['type'] ?? '') !== 'assistant' || !is_array($message['tokens'] ?? null)) {
                    continue;
                }
                $found = true;
                $this->addStepUsage(['tokens' => $message['tokens'], 'cost' => $message['cost'] ?? null], $run);
            }

            return $found ? array_merge($state, $run) : $state;
        } catch (\Throwable $e) {
            Log::channel('api')->debug('OpenCodeProvider: session export for usage failed', ['error' => $e->getMessage()]);

            return $state;
        }
    }

    protected function getCompletionSummary(array $state, int $exitCode): array
    {
        return [
            'exit_code' => $exitCode,
            'session_id' => $state['sessionId'],
            'total_cost' => $state['totalCost'],
            'input_tokens' => $state['inputTokens'],
            'output_tokens' => $state['outputTokens'],
        ];
    }

    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        return false;
    }

    protected function parseJsonLine(string $line, array &$state, ?array $preDecoded = null): Generator
    {
        $data = $preDecoded ?? json_decode($line, true);
        if (!is_array($data)) {
            return; // log lines from the CLI
        }

        if (!empty($data['sessionID']) && is_string($data['sessionID'])) {
            $state['sessionId'] = $data['sessionID'];
        }

        $part = is_array($data['part'] ?? null) ? $data['part'] : [];

        switch ($data['type'] ?? '') {
            case 'text':
                $text = (string) ($part['text'] ?? '');
                if ($text !== '') {
                    yield StreamEvent::textStart($state['blockIndex']);
                    yield StreamEvent::textDelta($state['blockIndex'], $text);
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['blockIndex']++;
                }
                break;

            case 'reasoning':
                $text = (string) ($part['text'] ?? '');
                if (trim($text) !== '') {
                    yield StreamEvent::thinkingStart($state['blockIndex']);
                    yield StreamEvent::thinkingDelta($state['blockIndex'], $text);
                    yield StreamEvent::thinkingStop($state['blockIndex']);
                    $state['blockIndex']++;
                }
                break;

            case 'tool_use':
                yield from $this->emitTool($part, $state);
                break;

            case 'step_finish':
                $this->addStepUsage($part, $state);
                break;

            case 'error':
                $error = $data['error'] ?? [];
                $message = is_array($error)
                    ? ($error['data']['message'] ?? $error['message'] ?? $error['name'] ?? json_encode($error))
                    : (string) $error;
                Log::channel('api')->error('OpenCodeProvider: CLI error event', ['error' => $error]);
                yield StreamEvent::error('OpenCode: ' . $message);
                break;
        }
    }

    /**
     * tool_use arrives once the tool finished: emit call + result together.
     */
    private function emitTool(array $part, array &$state): Generator
    {
        $toolState = is_array($part['state'] ?? null) ? $part['state'] : [];
        $callId = (string) ($part['id'] ?? $part['callID'] ?? ('tool_' . $state['blockIndex']));
        $tool = (string) ($part['tool'] ?? 'tool');
        $name = self::displayToolName($tool);
        $input = self::normalizeToolArgs((array) ($toolState['input'] ?? []));

        yield StreamEvent::toolUseStart($state['blockIndex'], $callId, $name);
        yield StreamEvent::toolUseDelta($state['blockIndex'], (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        yield StreamEvent::toolUseStop($state['blockIndex']);
        $state['blockIndex']++;

        $isError = ($toolState['status'] ?? '') === 'error';
        $output = $isError ? ($toolState['error'] ?? 'Tool failed') : ($toolState['output'] ?? '');
        if (is_array($output)) {
            $output = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        yield StreamEvent::toolResult($callId, (string) $output, $isError);
    }

    /**
     * Map OpenCode tool ids to the Claude Code style names the chat UI knows.
     */
    private static function displayToolName(string $tool): string
    {
        return match (strtolower($tool)) {
            'shell', 'bash' => 'Bash',
            'read' => 'Read',
            'write' => 'Write',
            'edit', 'multiedit', 'apply_patch', 'patch' => 'Edit',
            'glob' => 'Glob',
            'grep' => 'Grep',
            'list', 'ls' => 'LS',
            'webfetch' => 'WebFetch',
            'websearch' => 'WebSearch',
            'todowrite' => 'TodoWrite',
            'todoread' => 'TodoRead',
            'task' => 'Task',
            default => $tool,
        };
    }

    /**
     * camelCase OpenCode args → snake_case fields used by the chat tool renderer.
     */
    private static function normalizeToolArgs(array $args): array
    {
        $map = ['filePath' => 'file_path', 'oldString' => 'old_string', 'newString' => 'new_string', 'replaceAll' => 'replace_all'];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $args) && !array_key_exists($to, $args)) {
                $args[$to] = $args[$from];
            }
        }

        return $args;
    }

    /**
     * Sum step usage. Context = the prompt of the last step (incl. cache).
     */
    private function addStepUsage(array $part, array &$state): void
    {
        $tokens = is_array($part['tokens'] ?? null) ? $part['tokens'] : [];
        $input = (int) ($tokens['input'] ?? 0);
        $output = (int) ($tokens['output'] ?? 0) + (int) ($tokens['reasoning'] ?? 0);
        $cacheRead = (int) ($tokens['cache']['read'] ?? 0);
        $cacheWrite = (int) ($tokens['cache']['write'] ?? 0);

        $state['inputTokens'] += $input + $cacheRead + $cacheWrite;
        $state['outputTokens'] += $output;
        $state['cacheReadTokens'] += $cacheRead;
        $state['cacheWriteTokens'] += $cacheWrite;
        $state['contextInputTokens'] = $input + $cacheRead + $cacheWrite;

        if (isset($part['cost']) && is_numeric($part['cost'])) {
            $state['totalCost'] = ($state['totalCost'] ?? 0.0) + (float) $part['cost'];
        }
    }
}
