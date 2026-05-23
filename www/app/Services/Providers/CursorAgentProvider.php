<?php

namespace App\Services\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cursor Agent CLI provider.
 *
 * Uses the `agent` CLI tool with streaming JSON output.
 * Cursor Agent manages its own conversation history via chat IDs.
 *
 * CLI mapping:
 *   claude --print           => agent -p
 *   --output-format stream-json => --output-format stream-json
 *   --model <model>          => --model <model>
 *   --resume <sessionId>     => --resume <chatId>
 *   --dangerously-skip-permissions => --force --trust
 *   --verbose                => --stream-partial-output
 *   --system-prompt          => .cursor/rules/pocketdev-instructions.mdc (alwaysApply)
 */
class CursorAgentProvider extends AbstractCliProvider
{
    /**
     * Max bytes for PocketDev instructions written to .cursor/rules/.
     * PocketDev prompts can be large (tools + memory + skills).
     */
    private const RULES_MAX_BYTES = 512_000;

    private const RULES_FILENAME_PREFIX = 'pocketdev-instructions-';

    /**
     * Path to the rules file written for the current run (for cleanup).
     */
    private ?string $rulesFilePath = null;

    public function __construct(ModelRepository $models)
    {
        parent::__construct($models);
    }

    // ========================================================================
    // Provider identity
    // ========================================================================

    public function getProviderType(): string
    {
        return 'cursor_agent';
    }

    /**
     * Ensure PocketDev rules file is removed on success, abort, and error paths.
     */
    public function streamMessage(Conversation $conversation, array $options = []): Generator
    {
        try {
            yield from parent::streamMessage($conversation, $options);
        } finally {
            $this->cleanupPocketDevRulesFile();
        }
    }

    // ========================================================================
    // HasNativeSession implementation
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
    // Authentication
    // ========================================================================

    /**
     * Check if Cursor auth.json exists and contains valid credentials,
     * or if an API key is configured in settings.
     */
    public function hasCredentials(): bool
    {
        // Check API key in database
        $settings = app(AppSettingsService::class);
        if ($settings->hasCursorAgentApiKey()) {
            return true;
        }

        // Check auth file
        $home = getenv('HOME') ?: '/home/appuser';
        $authFile = $home . '/.config/cursor/auth.json';

        if (!is_readable($authFile)) {
            return false;
        }

        $content = @file_get_contents($authFile);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }

        // Valid if has accessToken (Cursor OAuth format)
        if (!empty($data['accessToken'])) {
            return true;
        }

        return false;
    }

    /**
     * Cursor authentication is handled via ~/.config/cursor/auth.json
     * (set up via `agent login` for subscription) or API key in database.
     */
    public function isAuthenticated(): bool
    {
        return $this->hasCredentials();
    }

    // ========================================================================
    // Template method implementations
    // ========================================================================

    /**
     * Override getModels to use dynamic CLI discovery instead of static config.
     * Falls back to static config if CLI is unavailable.
     */
    public function getModels(): array
    {
        $dynamic = self::discoverModels();
        if (!empty($dynamic)) {
            return collect($dynamic)
                ->mapWithKeys(fn (array $model) => [
                    $model['model_id'] => [
                        'name' => $model['display_name'],
                        'context_window' => $model['context_window'],
                        'max_output_tokens' => $model['max_output_tokens'],
                    ],
                ])
                ->toArray();
        }
        return parent::getModels();
    }

    protected function isCliBinaryAvailable(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $agentPath = $home . '/.local/bin/agent';

        // Check specific known path first (avoids false positives from generic 'agent' name)
        if (file_exists($agentPath) && is_executable($agentPath)) {
            return true;
        }

        // Fallback to which
        $output = [];
        $returnCode = 0;
        exec('which agent 2>/dev/null', $output, $returnCode);
        return $returnCode === 0 && !empty($output);
    }

    protected function hasAuthCredentials(): bool
    {
        return $this->hasCredentials();
    }

    protected function getAuthRequiredError(): string
    {
        return 'CURSOR_AGENT_AUTH_REQUIRED:Cursor Agent authentication required. Please set up authentication at /cursor/auth';
    }

    protected function buildCliCommand(
        Conversation $conversation,
        array $options
    ): string {
        $baseModel = $conversation->model ?? config('ai.providers.cursor_agent.default_model', 'auto');

        // Resolve base model + thinking + effort + fast into the final model ID
        // E.g. "claude-opus-4-7" + thinking + high        → "claude-opus-4-7-thinking-high"
        // E.g. "claude-opus-4-7" + thinking + high + fast  → "claude-opus-4-7-thinking-high-fast"
        // E.g. "claude-opus-4-7" + !thinking + max         → "claude-opus-4-7-max"
        $reasoningConfig = $conversation->getReasoningConfig();
        $effort = $reasoningConfig['effort'] ?? 'high';
        $thinking = $reasoningConfig['thinking'] ?? true;
        $fast = $reasoningConfig['fast'] ?? false;
        $modelConfig = $this->getModelConfig($baseModel);
        $resolvedModel = $this->resolveModelId($baseModel, $effort, $thinking, $modelConfig);

        // Append -fast suffix if requested — verify the fast variant actually exists
        if ($fast && ($modelConfig['effort_variants']['has_fast'] ?? false)) {
            $fastModel = $resolvedModel . '-fast';
            // Check against discovered models to avoid invalid combinations
            $allModels = self::getKnownModelIds();
            if (empty($allModels) || isset($allModels[$fastModel])) {
                $resolvedModel = $fastModel;
            }
        }

        // Ensure ~/.cursor directories are writable BEFORE syncing MCP config
        // (syncMcpServersFromClaudeCode may create ~/.cursor with wrong permissions)
        $home = getenv('HOME') ?: '/home/appuser';
        $this->ensureCursorDirectories($home);

        // Sync MCP servers from Claude Code config to Cursor config
        $workingDir = $conversation->working_directory ?? '/workspace';
        $this->syncMcpServersFromClaudeCode($workingDir);

        // Inject PocketDev system prompt (tools, memory, skills) via Cursor project rules.
        // Unlike Claude Code (--system-prompt) the agent CLI has no flag; rules are the supported path.
        if (!empty($options['system'])) {
            $rulesDir = rtrim($workingDir, '/') . '/.cursor/rules';
            $rulesFile = self::RULES_FILENAME_PREFIX . $conversation->id . '.mdc';
            $this->rulesFilePath = $rulesDir . '/' . $rulesFile;
            $this->writePocketDevRulesFile($this->rulesFilePath, $options['system']);
        }

        // Use absolute path because the queue worker's PATH may not include ~/.local/bin
        $agentBin = $home . '/.local/bin/agent';
        if (!file_exists($agentBin)) {
            // Fallback to which
            $whichResult = shell_exec('which agent 2>/dev/null');
            $agentBin = ($whichResult !== null ? trim($whichResult) : '') ?: 'agent';
        }

        $parts = [
            $agentBin,
            '-p',
            '--output-format', 'stream-json',
            '--model', escapeshellarg($resolvedModel),
            '--force',
            '--trust',
            '--approve-mcps',
        ];

        // Use --resume for conversation continuity
        $sessionId = $this->getSessionId($conversation);
        if (!empty($sessionId)) {
            $parts[] = '--resume';
            $parts[] = escapeshellarg($sessionId);
        }

        // Set workspace directory
        $parts[] = '--workspace';
        $parts[] = escapeshellarg($workingDir);

        return implode(' ', $parts);
    }

    protected function prepareProcessInput(string $command, string $userMessage): array
    {
        // Cursor Agent takes user message via stdin (same as Claude Code)
        return [
            'command' => $command,
            'stdin' => $userMessage,
        ];
    }

    protected function buildEnvironment(Conversation $conversation, array $options): array
    {
        $env = parent::buildEnvironment($conversation, $options);

        // Inject API key if configured (for API key mode, not subscription)
        $settings = app(AppSettingsService::class);
        $apiKey = $settings->getCursorAgentApiKey();
        if (!empty($apiKey)) {
            $env['CURSOR_API_KEY'] = $apiKey;
        }

        return $env;
    }

    protected function initParseState(): array
    {
        return [
            'blockIndex' => 0,
            'textStarted' => false,
            'thinkingStarted' => false,
            'currentToolUse' => null,
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
        $peekType = $parsedData['type'] ?? '';
        $subtype = $parsedData['subtype'] ?? '';

        // Tool progress heartbeats
        if ($peekType === 'tool_progress') {
            return ['phase' => null, 'resetsTimer' => true, 'shouldSkip' => true];
        }

        $phase = null;
        $resetsTimer = true;

        switch ($peekType) {
            case 'thinking':
                $phase = 'streaming';
                break;

            case 'tool_call':
                $phase = ($subtype === 'started') ? 'tool_execution' : 'pending_response';
                break;

            case 'assistant':
                $phase = 'streaming';
                break;

            case 'user':
                $phase = 'pending_response';
                break;

            case 'result':
                $phase = 'streaming';
                break;

            case 'system':
                $phase = 'initial';
                break;
        }

        return ['phase' => $phase, 'resetsTimer' => $resetsTimer, 'shouldSkip' => false];
    }

    protected function shouldLogEvent(array $parsedData): bool
    {
        $verboseLogging = config('ai.providers.cursor_agent.verbose_logging', false);
        $eventType = $parsedData['type'] ?? null;
        return $verboseLogging || $eventType !== 'stream_event';
    }

    protected function closeOpenBlocks(array $state): Generator
    {
        if ($state['textStarted']) {
            yield StreamEvent::textStop($state['blockIndex']);
        }
        if ($state['thinkingStarted']) {
            yield StreamEvent::thinkingStop($state['blockIndex']);
        }
        if ($state['currentToolUse'] !== null) {
            yield StreamEvent::toolUseStop($state['blockIndex']);
        }
    }

    protected function emitUsage(array $state): Generator
    {
        if ($state['totalCost'] !== null || $state['inputTokens'] > 0) {
            $cacheWrite = $state['cacheWriteTokens'] > 0 ? $state['cacheWriteTokens'] : null;
            $cacheRead = $state['cacheReadTokens'] > 0 ? $state['cacheReadTokens'] : null;
            $contextInput = $state['contextInputTokens'] > 0 ? $state['contextInputTokens'] : null;
            $contextOutput = $state['outputTokens'] > 0 ? $state['outputTokens'] : null;

            yield StreamEvent::usage(
                $state['inputTokens'],
                $state['outputTokens'],
                $cacheWrite,
                $cacheRead,
                $state['totalCost'],
                null,
                $contextInput,
                $contextOutput
            );
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

    // ========================================================================
    // Model ID Resolution (effort level → model name)
    // ========================================================================

    /**
     * Look up the model config by base model ID.
     * Checks dynamic models first, then falls back to static config.
     */
    private function getModelConfig(string $baseModelId): array
    {
        // Try dynamic models first
        $dynamic = self::discoverModels();
        foreach ($dynamic as $model) {
            if (($model['model_id'] ?? '') === $baseModelId) {
                return $model;
            }
        }
        // Fallback to static config
        foreach (config('ai.models.cursor_agent', []) as $model) {
            if (($model['model_id'] ?? '') === $baseModelId) {
                return $model;
            }
        }
        // Unknown model, treat as no effort control
        return ['model_id' => $baseModelId, 'effort_variants' => null];
    }

    /**
     * Dynamically discover available models from `agent models` CLI output.
     *
     * Parses the raw model list, groups variants into base families,
     * and infers effort_variants + thinking support from naming patterns.
     * Results are cached for 1 hour.
     *
     * @return array List of model configs compatible with ai.php format
     */
    public static function discoverModels(): array
    {
        return Cache::remember('cursor_agent:models', 3600, function () {
            $home = getenv('HOME') ?: '/home/appuser';
            $agentBin = $home . '/.local/bin/agent';
            if (!file_exists($agentBin)) {
                $agentBin = 'agent';
            }

            $output = shell_exec($agentBin . ' models 2>&1');
            if ($output === null) {
                Log::warning('cursor_agent: Failed to run agent models');
                return [];
            }

            // Parse "model_id - Display Name" lines
            $rawModels = [];
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if (str_contains($line, ' - ') && !str_starts_with($line, 'Available') && !str_starts_with($line, 'Tip:')) {
                    [$id, $name] = explode(' - ', $line, 2);
                    $id = trim($id);
                    $name = trim(str_replace([' (current)', ' (default)'], '', $name));
                    $rawModels[$id] = $name;
                }
            }

            if (empty($rawModels)) {
                return [];
            }

            // Group into base families by stripping known suffixes
            $families = [];
            foreach ($rawModels as $id => $name) {
                $base = self::extractBaseModel($id);
                if (!isset($families[$base])) {
                    $families[$base] = ['variants' => [], 'display' => null];
                }
                $families[$base]['variants'][$id] = $name;
            }

            // Build model configs for each family
            $models = [];
            foreach ($families as $base => $family) {
                $variants = $family['variants'];
                $variantIds = array_keys($variants);

                // Determine thinking support (has both X and X-thinking variants)
                $hasThinking = false;
                $hasFast = false;
                foreach ($variantIds as $vid) {
                    if (str_contains($vid, 'thinking')) {
                        $hasThinking = true;
                    }
                    if (str_ends_with($vid, '-fast')) {
                        $hasFast = true;
                    }
                }

                // Determine effort levels
                $effortLevels = self::inferEffortLevels($base, $variantIds);
                $type = self::inferVariantType($base, $variantIds, $hasThinking);

                // Pick display name from the "default" variant
                $displayName = $variants[$base]
                    ?? $variants[array_key_first($variants)]
                    ?? ucwords(str_replace('-', ' ', $base));
                // Clean display name: remove effort/thinking suffixes
                $displayName = preg_replace('/\s*(Low|Medium|High|Extra High|Max|Thinking|Fast|None|1M)\s*/i', ' ', $displayName);
                $displayName = trim(preg_replace('/\s+/', ' ', $displayName));
                if (empty($displayName)) {
                    $displayName = ucwords(str_replace('-', ' ', $base));
                }

                $effortVariants = null;
                if (!empty($effortLevels) || $hasThinking || $hasFast) {
                    // Only set type if there are actual effort levels or thinking;
                    // fast-only models (like composer) just need has_fast flag
                    $effectiveType = (!empty($effortLevels) || $hasThinking) ? $type : 'none';
                    $effortVariants = [
                        'type' => $effectiveType,
                        'has_thinking' => $hasThinking,
                        'has_fast' => $hasFast,
                    ];
                    if (!empty($effortLevels)) {
                        $effortVariants['levels'] = $effortLevels;
                        $effortVariants['default'] = in_array('high', $effortLevels) ? 'high'
                            : (in_array('medium', $effortLevels) ? 'medium' : $effortLevels[0]);
                        // Map xhigh → extra-high for GPT-5.5
                        if (in_array('extra-high', $effortLevels)) {
                            $effortVariants['level_map'] = ['xhigh' => 'extra-high'];
                        }
                        // Map medium → '' for models where base = default (gpt-5.2, gpt-5.3-codex etc.)
                        if (isset($rawModels[$base]) && !isset($rawModels[$base . '-medium'])) {
                            $effortVariants['level_map'] = array_merge(
                                $effortVariants['level_map'] ?? [],
                                ['medium' => '']
                            );
                        }
                    }
                }

                $has1M = str_contains($variants[array_key_first($variants)] ?? '', '1M');

                $models[] = [
                    'model_id'                      => $base,
                    'display_name'                  => $displayName,
                    'effort_variants'               => $effortVariants,
                    'context_window'                => 200000,
                    'max_context_window'            => 200000,
                    'max_output_tokens'             => str_contains($base, 'opus') ? 128000 : 64000,
                    'input_price_per_million'       => null,
                    'output_price_per_million'      => null,
                    'cache_write_price_per_million' => null,
                    'cache_read_price_per_million'  => null,
                ];
            }

            // Sort: auto first, then Claude, then GPT, then others
            usort($models, function ($a, $b) {
                $order = fn($id) => match (true) {
                    $id === 'auto' => 0,
                    str_starts_with($id, 'claude-opus-4-7') => 1,
                    str_starts_with($id, 'claude') => 2,
                    str_starts_with($id, 'gpt-5.5') => 3,
                    str_starts_with($id, 'gpt-5.4') && !str_contains($id, 'mini') && !str_contains($id, 'nano') => 4,
                    str_starts_with($id, 'gpt') => 5,
                    str_starts_with($id, 'composer') => 8,
                    default => 6,
                };
                return $order($a['model_id']) <=> $order($b['model_id']);
            });

            Log::info('cursor_agent: Discovered ' . count($models) . ' model families from CLI');

            return $models;
        });
    }

    /**
     * Get all known model IDs from the CLI (cached).
     * Returns a map of model_id => true for fast lookup.
     */
    private static function getKnownModelIds(): array
    {
        return Cache::remember('cursor_agent:all_model_ids', 3600, function () {
            $home = getenv('HOME') ?: '/home/appuser';
            $agentBin = $home . '/.local/bin/agent';
            if (!file_exists($agentBin)) {
                $agentBin = 'agent';
            }

            $output = shell_exec($agentBin . ' models 2>&1');
            if ($output === null) {
                return [];
            }

            $ids = [];
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if (str_contains($line, ' - ') && !str_starts_with($line, 'Available') && !str_starts_with($line, 'Tip:')) {
                    [$id] = explode(' - ', $line, 2);
                    $ids[trim($id)] = true;
                }
            }
            return $ids;
        });
    }

    /**
     * Extract the base model ID by stripping effort/thinking/fast suffixes.
     */
    private static function extractBaseModel(string $modelId): string
    {
        $m = $modelId;
        // Remove -fast
        $m = preg_replace('/-fast$/', '', $m);
        // Remove -thinking-{level} (prefix pattern: opus 4.7)
        $m = preg_replace('/-thinking-(low|medium|high|xhigh|max|extra-high)$/', '', $m);
        // Remove -{level}-thinking (suffix pattern: opus 4.6)
        $m = preg_replace('/-(low|medium|high|max)-thinking$/', '', $m);
        // Remove -thinking (toggle pattern: sonnet 4.5)
        $m = preg_replace('/-thinking$/', '', $m);
        // Remove effort suffixes
        $m = preg_replace('/-(none|low|medium|high|xhigh|max|extra-high)$/', '', $m);

        return $m;
    }

    /**
     * Infer available effort levels from variant model IDs.
     */
    private static function inferEffortLevels(string $base, array $variantIds): array
    {
        $levels = [];
        $knownLevels = ['none', 'low', 'medium', 'high', 'xhigh', 'max', 'extra-high'];

        foreach ($variantIds as $vid) {
            // Strip -fast and -thinking parts to isolate effort
            $clean = preg_replace('/-fast$/', '', $vid);
            $clean = preg_replace('/-thinking/', '', $clean);

            // Check what's left after removing base
            $suffix = substr($clean, strlen($base));
            $suffix = ltrim($suffix, '-');

            if (in_array($suffix, $knownLevels) && !in_array($suffix, $levels)) {
                $levels[] = $suffix;
            }
        }

        // Sort by known order
        $order = array_flip($knownLevels);
        usort($levels, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return $levels;
    }

    /**
     * Infer the variant type pattern from model IDs.
     */
    private static function inferVariantType(string $base, array $variantIds, bool $hasThinking): string
    {
        if (!$hasThinking) {
            return 'suffix';
        }

        // Check for prefix_thinking pattern: {base}-thinking-{level}
        foreach ($variantIds as $vid) {
            if (preg_match('/^' . preg_quote($base) . '-thinking-(low|medium|high|xhigh|max)/', $vid)) {
                return 'prefix_thinking';
            }
        }

        // Check for suffix_thinking pattern: {base}-{level}-thinking
        foreach ($variantIds as $vid) {
            if (preg_match('/^' . preg_quote($base) . '-(low|medium|high|max)-thinking/', $vid)) {
                return 'suffix_thinking';
            }
        }

        // Default: toggle_thinking (just {base}-thinking)
        return 'toggle_thinking';
    }

    /**
     * Resolve a base model + thinking toggle + effort level into the actual CLI model ID.
     *
     * Thinking and effort are TWO INDEPENDENT axes:
     *   prefix_thinking: claude-opus-4-7 + thinking + high → claude-opus-4-7-thinking-high
     *                    claude-opus-4-7 + !thinking + max → claude-opus-4-7-max
     *   suffix_thinking: claude-4.6-opus + thinking + high → claude-4.6-opus-high-thinking
     *                    claude-4.6-opus + !thinking + high → claude-4.6-opus-high
     *   toggle_thinking: claude-4.5-sonnet + thinking      → claude-4.5-sonnet-thinking
     *                    claude-4.5-sonnet + !thinking     → claude-4.5-sonnet
     *   suffix:          gpt-5.5 + medium                 → gpt-5.5-medium
     *   null:            auto                             → auto
     */
    private function resolveModelId(string $baseModel, string $effort, bool $thinking, array $modelConfig): string
    {
        $variants = $modelConfig['effort_variants'] ?? null;

        // No effort variants — return base model as-is
        if ($variants === null) {
            return $baseModel;
        }

        $type = $variants['type'] ?? 'suffix';
        $hasThinking = $variants['has_thinking'] ?? false;
        $availableLevels = $variants['levels'] ?? [];
        $default = $variants['default'] ?? ($availableLevels[0] ?? 'medium');

        // Map effort names that differ between our UI and Cursor's model names
        $levelMap = $variants['level_map'] ?? [];

        // Validate effort is available; fall back to default
        if (!empty($availableLevels) && !in_array($effort, $availableLevels) && !isset($levelMap[$effort])) {
            $effort = $default;
        }

        $mappedEffort = $levelMap[$effort] ?? $effort;

        // If model doesn't support thinking, ignore the toggle
        $useThinking = $thinking && $hasThinking;

        // Build effort suffix (empty string → no suffix to avoid trailing hyphens)
        $effortSuffix = ($mappedEffort !== '') ? '-' . $mappedEffort : '';

        return match ($type) {
            // Opus 4.7 pattern: {base}-thinking-{level} or {base}-{level}
            'prefix_thinking' => $useThinking
                ? $baseModel . '-thinking' . $effortSuffix
                : $baseModel . $effortSuffix,

            // Opus 4.6 pattern: {base}-{level}-thinking or {base}-{level}
            'suffix_thinking' => $useThinking
                ? $baseModel . $effortSuffix . '-thinking'
                : $baseModel . $effortSuffix,

            // Sonnet 4.5/4 pattern: {base}-thinking or {base}
            'toggle_thinking' => $useThinking
                ? $baseModel . '-thinking'
                : $baseModel,

            // GPT pattern: {base}-{level} (no thinking toggle)
            'suffix' => $baseModel . $effortSuffix,

            default => $baseModel,
        };
    }

    /**
     * Cursor Agent manages its own history, so no session file sync needed.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        return false;
    }

    // ========================================================================
    // JSONL Parsing (Cursor Agent)
    // ========================================================================

    /**
     * Parse a JSONL line and yield StreamEvents.
     *
     * Cursor Agent uses a different stream format than Claude Code:
     * - system/init: Session init with session_id, model
     * - user: User message echo
     * - thinking/delta: Thinking text delta (streaming)
     * - thinking/completed: Thinking block done
     * - tool_call/started: Tool execution started (with tool details)
     * - tool_call/completed: Tool execution completed (with output)
     * - assistant: Full assistant message (with content blocks)
     * - result: Final result with session_id, usage stats
     * - error: Error message
     */
    protected function parseJsonLine(string $line, array &$state, ?array $preDecoded = null): Generator
    {
        $data = $preDecoded ?? json_decode($line, true);

        if (!is_array($data)) {
            // Cursor Agent may emit non-JSON lines (e.g., "S: Named models unavailable...")
            // Skip them gracefully instead of logging as errors
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '{')) {
                Log::channel('api')->debug('CursorAgentProvider: Non-JSON line from CLI', [
                    'line' => substr($trimmed, 0, 200),
                ]);
            }
            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'system':
                // Init event: capture session_id
                if (isset($data['session_id'])) {
                    $state['sessionId'] = $data['session_id'];
                }
                break;

            case 'user':
                // User message echo, nothing to emit to frontend
                break;

            case 'thinking':
                yield from $this->parseThinkingEvent($data, $state);
                break;

            case 'tool_call':
                yield from $this->parseToolCallEvent($data, $state);
                break;

            case 'assistant':
                yield from $this->parseAssistantMessage($data, $state);
                break;

            case 'result':
                yield from $this->parseResultEvent($data, $state);
                break;

            case 'error':
                $message = $data['message'] ?? ($data['error'] ?? 'Unknown error');
                yield StreamEvent::error($message);
                break;

            default:
                Log::channel('api')->debug('CursorAgentProvider: Unknown event type', [
                    'type' => $type,
                    'keys' => array_keys($data),
                ]);
        }
    }

    /**
     * Handle thinking events (thinking/delta, thinking/completed).
     */
    private function parseThinkingEvent(array $data, array &$state): Generator
    {
        $subtype = $data['subtype'] ?? '';

        if ($subtype === 'delta') {
            $text = $data['text'] ?? '';
            if ($text !== '') {
                // Close text block if open (thinking comes before text)
                if ($state['textStarted']) {
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['textStarted'] = false;
                    $state['blockIndex']++;
                }
                if (!$state['thinkingStarted']) {
                    yield StreamEvent::thinkingStart($state['blockIndex']);
                    $state['thinkingStarted'] = true;
                }
                yield StreamEvent::thinkingDelta($state['blockIndex'], $text);
            }
        } elseif ($subtype === 'completed') {
            if ($state['thinkingStarted']) {
                yield StreamEvent::thinkingStop($state['blockIndex']);
                $state['thinkingStarted'] = false;
                $state['blockIndex']++;
            }
        }
    }

    /**
     * Handle tool_call events (tool_call/started, tool_call/completed).
     */
    private function parseToolCallEvent(array $data, array &$state): Generator
    {
        $subtype = $data['subtype'] ?? '';
        $callId = $data['call_id'] ?? ('tool_' . $state['blockIndex']);

        if ($subtype === 'started') {
            // Close any open text/thinking blocks
            if ($state['textStarted']) {
                yield StreamEvent::textStop($state['blockIndex']);
                $state['textStarted'] = false;
                $state['blockIndex']++;
            }
            if ($state['thinkingStarted']) {
                yield StreamEvent::thinkingStop($state['blockIndex']);
                $state['thinkingStarted'] = false;
                $state['blockIndex']++;
            }

            // Determine tool name from the tool_call structure
            // Format: {"tool_call": {"shellToolCall": {..., "description": "..."}, ...}}
            $toolCall = $data['tool_call'] ?? [];
            $toolName = 'unknown';
            $inputJson = '{}';

            foreach ($toolCall as $key => $value) {
                if (is_array($value)) {
                    // Convert camelCase key to readable name (e.g., shellToolCall -> shell)
                    $toolName = str_replace('ToolCall', '', $key);
                    $toolName = str_replace('toolCall', '', $toolName) ?: $key;

                    // Extract relevant args for display
                    $args = $value['args'] ?? $value;
                    unset($args['result']);
                    // Remove internal fields, keep user-visible ones
                    unset($args['toolCallId'], $args['skipApproval'], $args['simpleCommands'],
                          $args['hasInputRedirect'], $args['hasOutputRedirect'], $args['parsingResult'],
                          $args['fileOutputThresholdBytes'], $args['isBackground'], $args['timeoutBehavior'],
                          $args['hardTimeout'], $args['closeStdin'], $args['workingDirectory'], $args['timeout']);
                    $toolName = $this->normalizeCursorToolDisplayName($toolName);
                    $args = $this->normalizeCursorToolArgs($toolName, $args);
                    $inputJson = json_encode($args);
                    break;
                }
            }

            $state['currentToolUse'] = [
                'id' => $callId,
                'name' => $toolName,
            ];

            yield StreamEvent::toolUseStart($state['blockIndex'], $callId, $toolName);
            yield StreamEvent::toolUseDelta($state['blockIndex'], $inputJson);

        } elseif ($subtype === 'completed') {
            // Tool completed with output
            if ($state['currentToolUse'] !== null) {
                yield StreamEvent::toolUseStop($state['blockIndex']);
                $state['currentToolUse'] = null;
                $state['blockIndex']++;
            }

            $toolCall = $data['tool_call'] ?? [];
            [$output, $isError] = $this->extractCursorToolResult($toolCall);
            if ($output === '' && isset($data['output'])) {
                $output = $data['output'];
                if (is_array($output)) {
                    $output = json_encode($output);
                }
            }
            yield StreamEvent::toolResult($callId, (string) $output, $isError);
        }
    }

    /**
     * Map Cursor CLI tool names to PocketDev UI labels (Claude Code conventions).
     */
    private function normalizeCursorToolDisplayName(string $toolName): string
    {
        return match (strtolower($toolName)) {
            'shell' => 'Bash',
            default => $toolName,
        };
    }

    /**
     * Normalize Cursor tool args to Claude Code field names for the chat UI.
     *
     * Cursor uses path/streamContent; PocketDev formatToolContent expects file_path/old_string/new_string.
     */
    private function normalizeCursorToolArgs(string $toolName, array $args): array
    {
        if (isset($args['path']) && !isset($args['file_path'])) {
            $args['file_path'] = $args['path'];
        }

        $lower = strtolower($toolName);

        if (in_array($lower, ['edit', 'write', 'strreplace', 'searchreplace'], true)) {
            if (isset($args['streamContent']) && !isset($args['new_string'])) {
                $args['new_string'] = $args['streamContent'];
            }
            if (isset($args['oldText']) && !isset($args['old_string'])) {
                $args['old_string'] = $args['oldText'];
            }
            if (isset($args['newText']) && !isset($args['new_string'])) {
                $args['new_string'] = $args['newText'];
            }
        }

        if ($lower === 'glob' && isset($args['globPattern']) && !isset($args['pattern'])) {
            $args['pattern'] = $args['globPattern'];
        }

        return $args;
    }

    /**
     * Extract human-readable tool output from Cursor's nested tool_call.result structure.
     *
     * @return array{0: string, 1: bool} [content, isError]
     */
    private function extractCursorToolResult(array $toolCall): array
    {
        foreach ($toolCall as $value) {
            if (!is_array($value)) {
                continue;
            }

            $result = $value['result'] ?? null;
            if (!is_array($result)) {
                continue;
            }

            if (isset($result['error']) && is_array($result['error'])) {
                $message = $result['error']['modelVisibleError']
                    ?? $result['error']['error']
                    ?? json_encode($result['error'], JSON_UNESCAPED_UNICODE);

                return [(string) $message, true];
            }

            if (isset($result['rejected']) && is_array($result['rejected'])) {
                $reason = $result['rejected']['reason'] ?? 'Command rejected';
                $command = $result['rejected']['command'] ?? '';

                return [trim($command . ($reason !== '' ? "\n" . $reason : '')), true];
            }

            if (isset($result['success']) && is_array($result['success'])) {
                $success = $result['success'];

                if (!empty($success['message'])) {
                    return [(string) $success['message'], false];
                }
                if (!empty($success['diffString'])) {
                    return [(string) $success['diffString'], false];
                }
                if (!empty($success['content'])) {
                    $content = (string) $success['content'];
                    // Avoid dumping entire files into the tool result panel
                    if (strlen($content) > 4000) {
                        $path = $success['path'] ?? 'file';
                        $lines = $success['totalLines'] ?? '?';

                        return ["Read {$path} ({$lines} lines)", false];
                    }

                    return [$content, false];
                }

                return [json_encode($success, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), false];
            }
        }

        return ['', false];
    }

    /**
     * Handle result event with usage stats.
     */
    private function parseResultEvent(array $data, array &$state): Generator
    {
        if (isset($data['session_id'])) {
            $state['sessionId'] = $data['session_id'];
        }
        if (isset($data['chat_id'])) {
            $state['sessionId'] = $data['chat_id'];
        }

        // Extract usage (Cursor uses camelCase: inputTokens, outputTokens)
        $usage = $data['usage'] ?? [];
        if (!empty($usage)) {
            $promptInput = (int) ($usage['inputTokens'] ?? $usage['input_tokens'] ?? 0);
            $state['cacheReadTokens'] = (int) ($usage['cacheReadTokens'] ?? $usage['cache_read_input_tokens'] ?? 0);
            $state['cacheWriteTokens'] = (int) ($usage['cacheWriteTokens'] ?? $usage['cache_creation_input_tokens'] ?? 0);
            $state['contextInputTokens'] = $promptInput;
            $state['inputTokens'] = $promptInput + $state['cacheReadTokens'] + $state['cacheWriteTokens'];
            $state['outputTokens'] = (int) ($usage['outputTokens'] ?? $usage['output_tokens'] ?? 0);
        }

        if (isset($data['total_cost_usd'])) {
            $state['totalCost'] = (float) $data['total_cost_usd'];
        }

        Log::channel('api')->info('CursorAgentProvider: Result received', [
            'subtype' => $data['subtype'] ?? 'unknown',
            'is_error' => $data['is_error'] ?? false,
            'session_id' => $state['sessionId'],
            'duration_ms' => $data['duration_ms'] ?? null,
        ]);

        if (!empty($data['is_error'])) {
            $errorMsg = $data['result'] ?? null;
            if (is_array($errorMsg)) {
                $errorMsg = json_encode($errorMsg);
            }
            if (($errorMsg === null || $errorMsg === '') && !empty($data['errors']) && is_array($data['errors'])) {
                $firstError = $data['errors'][0] ?? null;
                $errorMsg = is_array($firstError) ? json_encode($firstError) : $firstError;
            }
            if ($errorMsg === null || $errorMsg === '') {
                $errorMsg = 'Cursor Agent error: ' . ($data['subtype'] ?? 'unknown error');
            }
            $errorMsg = is_string($errorMsg) ? $errorMsg : (string) $errorMsg;

            Log::channel('api')->error('CursorAgentProvider: CLI returned error result', [
                'error_message' => $errorMsg,
            ]);
            yield StreamEvent::error($errorMsg);
        }
    }

    /**
     * Parse assistant message with content blocks.
     * Handles text, thinking, tool_use, and tool_result blocks.
     */
    private function parseAssistantMessage(array $data, array &$state): Generator
    {
        $message = $data['message'] ?? [];
        if (!is_array($message)) {
            return;
        }
        $content = $message['content'] ?? [];
        if (!is_array($content)) {
            return;
        }

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            switch ($blockType) {
                case 'text':
                    // Close thinking if open
                    if ($state['thinkingStarted']) {
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if (!$state['textStarted']) {
                        yield StreamEvent::textStart($state['blockIndex']);
                        $state['textStarted'] = true;
                    }
                    $text = $block['text'] ?? '';
                    if ($text !== '') {
                        yield StreamEvent::textDelta($state['blockIndex'], $text);
                    }
                    break;

                case 'thinking':
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if (!$state['thinkingStarted']) {
                        yield StreamEvent::thinkingStart($state['blockIndex']);
                        $state['thinkingStarted'] = true;
                    }
                    $thinking = $block['thinking'] ?? '';
                    if ($thinking !== '') {
                        yield StreamEvent::thinkingDelta($state['blockIndex'], $thinking);
                    }
                    break;

                case 'tool_use':
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if ($state['thinkingStarted']) {
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }

                    $toolId = $block['id'] ?? 'tool_' . $state['blockIndex'];
                    $toolName = $block['name'] ?? 'unknown';
                    $input = $block['input'] ?? [];

                    yield StreamEvent::toolUseStart($state['blockIndex'], $toolId, $toolName);
                    $inputJson = is_array($input) ? json_encode($input) : (string) $input;
                    yield StreamEvent::toolUseDelta($state['blockIndex'], $inputJson);
                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    $state['blockIndex']++;
                    break;

                case 'tool_result':
                    $toolId = $block['tool_use_id'] ?? 'unknown';
                    $resultContent = $block['content'] ?? '';
                    $isError = $block['is_error'] ?? false;
                    yield StreamEvent::toolResult($toolId, $resultContent, $isError);
                    break;
            }
        }
    }

    // ========================================================================
    // PocketDev instructions (system prompt → Cursor rules)
    // ========================================================================

    /**
     * Write PocketDev system prompt as an always-on Cursor rule in the workspace.
     */
    private function writePocketDevRulesFile(string $file, string $content): void
    {
        $contentBytes = strlen($content);

        if ($contentBytes > self::RULES_MAX_BYTES) {
            $contentKb = round($contentBytes / 1024, 1);
            $limitKb = round(self::RULES_MAX_BYTES / 1024, 1);

            Log::channel('api')->error('CursorAgentProvider: System prompt exceeds size limit', [
                'content_bytes' => $contentBytes,
                'limit_bytes' => self::RULES_MAX_BYTES,
                'file' => $file,
            ]);

            throw new \RuntimeException(
                "System prompt too large for Cursor Agent ({$contentKb}KB exceeds {$limitKb}KB limit). " .
                "Try reducing enabled tools, memory tables, or skills in PocketDev settings."
            );
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create Cursor rules directory: {$dir}");
        }

        $body = "---\n"
            . "description: PocketDev tools, memory schemas, skills, and agent instructions\n"
            . "alwaysApply: true\n"
            . "---\n\n"
            . $content;

        if (file_put_contents($file, $body, LOCK_EX) === false) {
            Log::channel('api')->error('CursorAgentProvider: Failed to write Cursor rules file', [
                'file' => $file,
            ]);
            throw new \RuntimeException("Failed to write Cursor rules file: {$file}");
        }

        Log::channel('api')->info('CursorAgentProvider: Wrote PocketDev instructions to Cursor rules', [
            'file' => $file,
            'content_bytes' => $contentBytes,
        ]);
    }

    private function cleanupPocketDevRulesFile(): void
    {
        $file = $this->rulesFilePath;
        if ($file !== null && file_exists($file)) {
            @unlink($file);
            $this->rulesFilePath = null;
        }
    }

    // ========================================================================
    // Directory setup
    // ========================================================================

    /**
     * Ensure Cursor CLI directories exist and are writable by the www-data group.
     * The agent CLI creates project-specific state under ~/.cursor/projects/
     * and chat history under ~/.cursor/chats/, which need group write access
     * when the queue worker (www-data) runs the CLI.
     */
    private function ensureCursorDirectories(string $home): void
    {
        $dirs = [
            $home . '/.cursor',
            $home . '/.cursor/projects',
            $home . '/.cursor/chats',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            } elseif (!is_writable($dir)) {
                @chmod($dir, 0770);
            }
        }
    }

    // ========================================================================
    // MCP server synchronization
    // ========================================================================

    /**
     * Sync MCP servers from Claude Code config (~/.claude.json) to Cursor config (~/.cursor/mcp.json).
     *
     * Both use JSON format with "mcpServers" key, making this simpler than the Codex TOML conversion.
     * Reads global and project-specific MCP servers from Claude Code config and writes them
     * to Cursor's mcp.json.
     */
    private function syncMcpServersFromClaudeCode(string $workingDir): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $claudeConfigPath = $home . '/.claude.json';
        $cursorConfigPath = $home . '/.cursor/mcp.json';

        if (!is_readable($claudeConfigPath)) {
            return; // No Claude config, nothing to sync
        }

        try {
            $claudeConfig = json_decode(file_get_contents($claudeConfigPath), true);
            if (!is_array($claudeConfig)) {
                return;
            }

            $mcpServers = [];

            // 1. Collect global MCP servers (supports both array and keyed-object formats)
            if (isset($claudeConfig['mcpServers']) && is_array($claudeConfig['mcpServers'])) {
                foreach ($claudeConfig['mcpServers'] as $key => $server) {
                    if (!is_array($server) || !isset($server['command'])) {
                        continue;
                    }
                    $name = isset($server['name']) ? (string) $server['name'] : (string) $key;
                    $mcpServers[$name] = $server;
                }
            }

            // 2. Collect project-specific MCP servers (deepest matching path wins)
            if (isset($claudeConfig['projects']) && is_array($claudeConfig['projects'])) {
                $bestMatch = null;
                $bestMatchLength = -1;

                foreach ($claudeConfig['projects'] as $projectPath => $projectConfig) {
                    $normalizedPath = rtrim($projectPath, '/');
                    if (($workingDir === $normalizedPath || str_starts_with($workingDir, $normalizedPath . '/'))
                        && isset($projectConfig['mcpServers'])
                        && strlen($normalizedPath) > $bestMatchLength) {
                        $bestMatch = $projectConfig;
                        $bestMatchLength = strlen($normalizedPath);
                    }
                }

                if ($bestMatch !== null) {
                    foreach ($bestMatch['mcpServers'] as $name => $server) {
                        if (isset($server['command'])) {
                            // Project-level overrides global
                            $mcpServers[$name] = $server;
                        }
                    }
                }
            }

            // 3. Read existing Cursor MCP config (preserve non-mcpServers keys)
            $existingConfig = [];
            $hadMcpServers = false;
            if (is_readable($cursorConfigPath)) {
                $existingConfig = json_decode(file_get_contents($cursorConfigPath), true) ?? [];
                $hadMcpServers = isset($existingConfig['mcpServers']);
            }

            // If Claude has no MCP servers and Cursor has no existing MCP servers, skip
            if (empty($mcpServers) && !$hadMcpServers) {
                return;
            }

            // 4. Merge Claude MCP servers into existing Cursor config (preserve Cursor-only servers)
            $existingServers = is_array($existingConfig['mcpServers'] ?? null)
                ? $existingConfig['mcpServers']
                : [];
            $newConfig = $existingConfig;
            $newConfig['mcpServers'] = array_merge($existingServers, $mcpServers);

            // 5. Write mcp.json
            $dir = dirname($cursorConfigPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $content = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (file_put_contents($cursorConfigPath, $content, LOCK_EX) === false) {
                Log::channel('api')->error('CursorAgentProvider: Failed to write Cursor MCP config', [
                    'file' => $cursorConfigPath,
                ]);
                return;
            }

            Log::channel('api')->info('CursorAgentProvider: Synced MCP servers from Claude Code', [
                'server_count' => count($mcpServers),
                'servers' => array_keys($mcpServers),
            ]);

        } catch (\Throwable $e) {
            Log::channel('api')->warning('CursorAgentProvider: Failed to sync MCP servers', [
                'error' => $e->getMessage(),
            ]);
            // Non-fatal: Cursor Agent can still work without MCP servers
        }
    }
}
