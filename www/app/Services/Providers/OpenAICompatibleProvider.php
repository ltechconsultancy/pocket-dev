<?php

namespace App\Services\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-Compatible API provider for local LLMs.
 *
 * Supports any server that implements the OpenAI Chat Completions API:
 * - KoboldCpp
 * - Ollama
 * - LM Studio
 * - LocalAI
 * - vLLM
 * - And many others
 *
 * Uses the standard Chat Completions API (/v1/chat/completions) instead of
 * OpenAI's proprietary Responses API.
 */
class OpenAICompatibleProvider extends AbstractApiProvider
{
    private ?string $apiKey;
    private string $baseUrl;
    private string $defaultModel;
    private int $maxTokens;
    private int $contextWindow;
    private AppSettingsService $settings;
    private ?\Psr\Http\Message\StreamInterface $activeStream = null;

    public function __construct(ModelRepository $models, AppSettingsService $settings)
    {
        parent::__construct($models);
        $this->settings = $settings;
        // Settings come from UI (stored in database via AppSettingsService)
        $this->baseUrl = $this->settings->getOpenAiCompatibleBaseUrl() ?? '';
        $this->apiKey = $this->settings->getOpenAiCompatibleApiKey();
        $this->defaultModel = $this->settings->getOpenAiCompatibleModel() ?? '';
        $this->maxTokens = (int) config('ai.providers.openai_compatible.max_tokens', 8192);
        $this->contextWindow = $this->settings->getOpenAiCompatibleContextWindow()
            ?? (int) config('ai.providers.openai_compatible.context_window', 32768);
    }

    public function getProviderType(): string
    {
        return 'openai_compatible';
    }

    public function isAvailable(): bool
    {
        // Available if base URL is configured (API key is optional for local LLMs)
        return !empty($this->baseUrl);
    }

    /**
     * Models offered by the server (GET /v1/models), falling back to the single
     * configured model. Contributor models (the provider may train on your prompts)
     * carry 'contributor' => true and a warning prefix in their name.
     */
    public function getModels(): array
    {
        $dynamic = self::discoverModels();
        if (!empty($dynamic)) {
            return collect($dynamic)
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

        // Return a single model entry based on configured model name
        $modelName = $this->defaultModel ?: 'Local Model';
        $modelId = $this->defaultModel ?: 'default';

        return [
            $modelId => [
                'name' => $modelName,
                'context_window' => $this->contextWindow,
                'max_output_tokens' => $this->maxTokens,
                'contributor' => self::isContributorModel($modelId),
            ],
        ];
    }

    /**
     * Per-model context window when the server's model list knows it,
     * otherwise the global UI setting.
     */
    public function getContextWindow(string $model): int
    {
        foreach (self::discoverModels() as $m) {
            if ($m['model_id'] === $model && !empty($m['context_window'])) {
                return (int) $m['context_window'];
            }
        }

        return $this->contextWindow;
    }

    /**
     * "Contributor" models are discounted because the provider may use your
     * prompts and completions to train its models (e.g. OpenCode Go's
     * gemini-4-argon-contributor, muse-spark-1.3-contributor).
     */
    public static function isContributorModel(string $modelIdOrName): bool
    {
        return str_contains(strtolower($modelIdOrName), 'contributor');
    }

    /**
     * Discover models from the configured server (GET {base}/v1/models).
     * For OpenCode (opencode.ai) the list is enriched with names and context
     * windows from models.dev, and models that are only served on a
     * non-Chat-Completions endpoint (Anthropic/Google) are left out.
     * Cached for 1 hour per base URL + key; failures are cached for 5 minutes.
     *
     * @return array<int, array{model_id: string, display_name: string, context_window: int, max_output_tokens: int, contributor: bool}>
     */
    public static function discoverModels(): array
    {
        $settings = app(AppSettingsService::class);
        $baseUrl = rtrim((string) $settings->getOpenAiCompatibleBaseUrl(), '/');
        if ($baseUrl === '') {
            return [];
        }
        $apiKey = $settings->getOpenAiCompatibleApiKey();
        $cacheKey = 'openai_compatible_models:' . md5($baseUrl . '|' . ($apiKey ?? ''));

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fallbackContext = $settings->getOpenAiCompatibleContextWindow()
            ?? (int) config('ai.providers.openai_compatible.context_window', 32768);
        $fallbackOutput = (int) config('ai.providers.openai_compatible.max_tokens', 8192);

        $ids = [];
        try {
            $request = \Illuminate\Support\Facades\Http::timeout(8)->acceptJson();
            if (!empty($apiKey)) {
                $request = $request->withToken($apiKey);
            }
            $response = $request->get($baseUrl . '/v1/models');
            if ($response->successful()) {
                foreach ((array) $response->json('data', []) as $row) {
                    if (!empty($row['id']) && is_string($row['id'])) {
                        $ids[] = $row['id'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OpenAICompatibleProvider: model discovery failed', ['error' => $e->getMessage()]);
        }

        $meta = self::modelsDevMetadata($baseUrl);
        if (empty($ids) && !empty($meta)) {
            // Some gateways (OpenCode) do not list models on this endpoint: use models.dev
            $ids = array_keys($meta);
        }

        $models = [];
        foreach (array_unique($ids) as $id) {
            $m = $meta[$id] ?? null;
            if ($m !== null && in_array($m['npm'] ?? '', ['@ai-sdk/anthropic', '@ai-sdk/google'], true)) {
                continue; // served on /anthropic or /google, not on /v1/chat/completions
            }
            $name = $m['name'] ?? $id;
            $contributor = self::isContributorModel($id) || self::isContributorModel($name);
            $models[] = [
                'model_id' => $id,
                'display_name' => $contributor ? '⚠ CONTRIBUTOR (traint op je data) · ' . $name : $name,
                'context_window' => (int) ($m['limit']['context'] ?? $fallbackContext),
                'max_output_tokens' => (int) ($m['limit']['output'] ?? $fallbackOutput),
                'contributor' => $contributor,
            ];
        }

        // Regular models first (alphabetical), contributor models last
        usort($models, fn ($a, $b) => [$a['contributor'], strtolower($a['display_name'])]
            <=> [$b['contributor'], strtolower($b['display_name'])]);

        \Illuminate\Support\Facades\Cache::put($cacheKey, $models, empty($models) ? 300 : 3600);

        return $models;
    }

    /**
     * models.dev metadata for the provider whose API base matches $baseUrl
     * (only for opencode.ai). Keyed by model id.
     */
    private static function modelsDevMetadata(string $baseUrl): array
    {
        if (!str_contains($baseUrl, 'opencode.ai')) {
            return [];
        }

        $cacheKey = 'models_dev_provider:' . md5($baseUrl);
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $models = [];
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->get('https://models.dev/api.json');
            foreach ($response->successful() ? (array) $response->json() : [] as $provider) {
                if (is_array($provider) && rtrim((string) ($provider['api'] ?? ''), '/') === $baseUrl . '/v1') {
                    foreach ((array) ($provider['models'] ?? []) as $id => $m) {
                        // Keep only what we use (the full catalog is several MB)
                        $models[$id] = [
                            'name' => $m['name'] ?? $id,
                            'npm' => $m['provider']['npm'] ?? null,
                            'limit' => $m['limit'] ?? [],
                        ];
                    }
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OpenAICompatibleProvider: models.dev lookup failed', ['error' => $e->getMessage()]);
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, $models, empty($models) ? 300 : 21600);

        return $models;
    }

    /**
     * Stream a message and yield StreamEvent objects.
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator {
        if (!$this->isAvailable()) {
            yield StreamEvent::error('OpenAI-compatible server not configured. Please set the base URL.');

            return;
        }

        yield from $this->streamChatCompletions($conversation, $options);
    }

    /**
     * Build standard Chat Completions messages from conversation.
     *
     * Converts to standard format:
     * - User messages → { role: "user", content: "text" }
     * - Assistant messages → { role: "assistant", content: "text" }
     * - System messages → { role: "system", content: "text" }
     * - Tool calls → { role: "assistant", tool_calls: [...] }
     * - Tool results → { role: "tool", tool_call_id: "...", content: "..." }
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->messages as $message) {
            $content = $message->content;

            // Handle string content (simple messages)
            if (is_string($content)) {
                $messages[] = [
                    'role' => $message->role,
                    'content' => $content,
                ];
                continue;
            }

            // Handle array content (complex messages with blocks)
            if (is_array($content)) {
                $textParts = [];
                $toolCalls = [];
                $toolResults = [];

                foreach ($content as $block) {
                    $type = $block['type'] ?? null;

                    switch ($type) {
                        case 'text':
                            $textParts[] = $block['text'];
                            break;

                        case 'tool_use':
                            // Collect tool calls for assistant message
                            $toolCalls[] = [
                                'id' => $block['id'],
                                'type' => 'function',
                                'function' => [
                                    'name' => $block['name'],
                                    'arguments' => is_array($block['input'])
                                        ? json_encode($block['input'])
                                        : ($block['input'] ?? '{}'),
                                ],
                            ];
                            break;

                        case 'tool_result':
                            // Collect tool results
                            $toolResults[] = [
                                'role' => 'tool',
                                'tool_call_id' => $block['tool_use_id'],
                                'content' => is_string($block['content'])
                                    ? $block['content']
                                    : json_encode($block['content']),
                            ];
                            break;

                        case 'thinking':
                            // Skip thinking blocks - not supported in standard API
                            break;

                        case 'interrupted':
                            // Skip interrupted markers - UI-only, not for API
                            break;
                    }
                }

                // Add text content if present
                if (!empty($textParts)) {
                    $messages[] = [
                        'role' => $message->role,
                        'content' => implode("\n", $textParts),
                    ];
                }

                // Add assistant message with tool calls
                if (!empty($toolCalls)) {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $toolCalls,
                    ];
                }

                // Add tool result messages
                foreach ($toolResults as $result) {
                    $messages[] = $result;
                }
            }
        }

        return $messages;
    }

    /**
     * Stream using the Chat Completions API.
     */
    private function streamChatCompletions(Conversation $conversation, array $options): Generator
    {
        $model = $conversation->model ?? $this->defaultModel;
        // If model is empty, use a placeholder that most servers accept
        if (empty($model)) {
            $model = 'default';
        }

        $url = rtrim($this->baseUrl, '/') . '/v1/chat/completions';

        // Build messages array from conversation
        $messages = $this->buildMessagesFromConversation($conversation);

        // Inject interruption reminder into the last user message if present
        if (!empty($options['interruption_reminder'])) {
            $messages = $this->injectInterruptionReminder($messages, $options['interruption_reminder']);
        }

        // Prepend system message if provided
        if (!empty($options['system'])) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $options['system'],
            ]);
        }

        // Response level determines max_tokens
        $responseLevel = $options['response_level'] ?? config('ai.response.default_level', 1);
        $responseConfig = config("ai.response.levels.{$responseLevel}");
        $maxTokens = $responseConfig['tokens'] ?? $this->maxTokens;

        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'max_tokens' => $maxTokens,
        ];

        // Add tools if provided (function calling)
        if (!empty($options['tools'])) {
            $body['tools'] = $this->convertToolsToChatCompletions($options['tools']);
            $body['tool_choice'] = 'auto';
        }

        // Add reasoning_effort if configured (some servers may support it)
        $reasoningConfig = $conversation->getReasoningConfig();
        $effort = $reasoningConfig['effort'] ?? 'none';
        if ($effort !== 'none') {
            $body['reasoning_effort'] = $effort;
        }

        $client = new \GuzzleHttp\Client();

        Log::channel('api')->info('OpenAICompatibleProvider: CHAT COMPLETIONS REQUEST', [
            'url' => $url,
            'model' => $model,
            'message_count' => count($messages),
            'max_tokens' => $maxTokens,
            'has_reasoning_effort' => $effort !== 'none',
            'has_tools' => isset($body['tools']),
        ]);

        // Build headers - API key is optional
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $body,
                'stream' => true,
                'timeout' => 300, // 5 minute timeout for slow local models
                'connect_timeout' => 10,
            ]);

            $stream = $response->getBody();
            $this->activeStream = $stream;
            $buffer = '';
            $eventCount = 0;

            // Track state
            $state = [
                'textStarted' => false,
                'blockIndex' => 0,
                'currentToolCall' => null,
                'pendingToolCalls' => [],
                'hasToolCalls' => false,
            ];

            while (!$stream->eof()) {
                $chunk = $stream->read(64);

                if ($chunk === '') {
                    usleep(1000);
                    continue;
                }

                $buffer .= $chunk;

                // Parse SSE events (data: prefix with double newline or single newline)
                while (preg_match('/data:\s*(.+?)(?:\r?\n\r?\n|\r?\n(?=data:))/s', $buffer, $matches, PREG_OFFSET_MATCH)) {
                    $data = trim($matches[1][0]);
                    $buffer = substr($buffer, $matches[0][1] + strlen($matches[0][0]));

                    if ($data === '[DONE]') {
                        Log::channel('api')->info('OpenAICompatibleProvider: [DONE] received');
                        if ($state['textStarted']) {
                            yield StreamEvent::textStop($state['blockIndex']);
                        }
                        $stopReason = $state['hasToolCalls'] ? 'tool_use' : 'end_turn';
                        yield StreamEvent::done($stopReason);
                        continue;
                    }

                    $eventCount++;
                    yield from $this->parseChatCompletionEvent($data, $state);
                }
            }

            // Handle case where stream ends without [DONE]
            if ($state['textStarted']) {
                yield StreamEvent::textStop($state['blockIndex']);
                yield StreamEvent::done('end_turn');
            }

            Log::debug('OpenAICompatibleProvider: Stream completed', [
                'event_count' => $eventCount,
            ]);

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::channel('api')->error('OpenAICompatibleProvider: CONNECTION ERROR', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            yield StreamEvent::error('Failed to connect to local LLM server at ' . $this->baseUrl . '. Is the server running?');
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $e->getMessage();

            Log::channel('api')->error('OpenAICompatibleProvider: CLIENT ERROR', [
                'status' => $e->getResponse()->getStatusCode(),
                'error' => $errorMessage,
                'raw_response' => $responseBody,
            ]);

            yield StreamEvent::error($errorMessage);
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            Log::channel('api')->error('OpenAICompatibleProvider: SERVER ERROR', [
                'status' => $e->getResponse()->getStatusCode(),
                'raw_response' => $responseBody,
            ]);
            yield StreamEvent::error('Local LLM server error: ' . $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            Log::channel('api')->error('OpenAICompatibleProvider: UNEXPECTED ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            yield StreamEvent::error($e->getMessage());
        }
    }

    /**
     * Parse a Chat Completions streaming event.
     *
     * Standard format:
     * {
     *   "choices": [{
     *     "delta": {
     *       "role": "assistant",
     *       "content": "Hello"
     *     },
     *     "finish_reason": null
     *   }]
     * }
     */
    private function parseChatCompletionEvent(string $data, array &$state): Generator
    {
        $payload = json_decode($data, true);

        if ($payload === null) {
            Log::warning('OpenAICompatibleProvider: Failed to parse JSON', [
                'data' => substr($data, 0, 500),
            ]);
            return;
        }

        $choices = $payload['choices'] ?? [];
        if (empty($choices)) {
            return;
        }

        $choice = $choices[0];
        $delta = $choice['delta'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        // Handle text content
        $content = $delta['content'] ?? null;
        if ($content !== null && $content !== '') {
            if (!$state['textStarted']) {
                yield StreamEvent::textStart($state['blockIndex']);
                $state['textStarted'] = true;
            }
            yield StreamEvent::textDelta($state['blockIndex'], $content);
        }

        // Handle tool calls (function calling)
        $toolCalls = $delta['tool_calls'] ?? null;
        if ($toolCalls !== null) {
            foreach ($toolCalls as $toolCall) {
                $index = $toolCall['index'] ?? 0;

                // New tool call starting
                if (isset($toolCall['id'])) {
                    $state['hasToolCalls'] = true;

                    // Close text block if open
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }

                    // Store blockIndex with the tool call for correct stop pairing
                    $state['pendingToolCalls'][$index] = [
                        'id' => $toolCall['id'],
                        'name' => $toolCall['function']['name'] ?? '',
                        'arguments' => '',
                        'blockIndex' => $state['blockIndex'],
                    ];

                    yield StreamEvent::toolUseStart(
                        $state['blockIndex'],
                        $toolCall['id'],
                        $toolCall['function']['name'] ?? ''
                    );

                    // Increment after each tool start to ensure unique indices
                    $state['blockIndex']++;
                }

                // Tool call arguments streaming
                if (isset($toolCall['function']['arguments'])) {
                    $args = $toolCall['function']['arguments'];
                    if (isset($state['pendingToolCalls'][$index])) {
                        $state['pendingToolCalls'][$index]['arguments'] .= $args;
                        // Use the stored blockIndex for this tool call
                        yield StreamEvent::toolUseDelta($state['pendingToolCalls'][$index]['blockIndex'], $args);
                    }
                }
            }
        }

        // Handle finish reason
        if ($finishReason !== null) {
            // Close any open tool calls using their stored blockIndex
            foreach ($state['pendingToolCalls'] as $index => $toolCall) {
                yield StreamEvent::toolUseStop($toolCall['blockIndex']);
            }
            $state['pendingToolCalls'] = [];

            // Handle different finish reasons
            if ($finishReason === 'tool_calls' || $finishReason === 'function_call') {
                $state['hasToolCalls'] = true;
            }
        }

        // Extract usage if present
        $usage = $payload['usage'] ?? null;
        if ($usage) {
            yield StreamEvent::usage(
                $usage['prompt_tokens'] ?? 0,
                $usage['completion_tokens'] ?? 0
            );
        }
    }

    /**
     * Abort the current streaming operation.
     */
    public function abort(): void
    {
        if ($this->activeStream !== null) {
            try {
                $this->activeStream->close();
            } catch (\Exception $e) {
                Log::warning('OpenAICompatibleProvider: Error closing stream', ['error' => $e->getMessage()]);
            }
            $this->activeStream = null;
        }
    }

    /**
     * Convert tools to Chat Completions format.
     */
    private function convertToolsToChatCompletions(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => []],
                ],
            ];
        }, $tools);
    }
}
