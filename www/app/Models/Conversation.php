<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use SoftDeletes;

    public const STATUS_IDLE = 'idle';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'agent_id',
        'provider_type',
        'model',
        'title',
        'tab_label',
        'working_directory',
        'total_input_tokens',
        'total_output_tokens',
        // Context window tracking
        'last_context_tokens',
        'context_window_size',
        'status',
        'last_activity_at',
        // Unified reasoning config (JSON)
        'reasoning_config',
        // Unified session ID for CLI providers
        'provider_session_id',
        'response_level',
        // Embedding tracking
        'last_embedded_turn_number',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
        'last_context_tokens' => 'integer',
        'context_window_size' => 'integer',
        'reasoning_config' => 'array',
        'response_level' => 'integer',
        'last_embedded_turn_number' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Conversation $conversation) {
            if (empty($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }
        });

        // Clean up stream log file when conversation is deleted
        static::deleting(function (Conversation $conversation) {
            app(\App\Services\ConversationStreamLogger::class)->delete($conversation->uuid);
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sequence');
    }

    /**
     * Check if the conversation has any messages (has "started").
     */
    public function hasMessages(): bool
    {
        return $this->messages()->exists();
    }

    public function turnEmbeddings(): HasMany
    {
        return $this->hasMany(ConversationTurnEmbedding::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Get the screen that displays this conversation (if any).
     */
    public function screen(): HasOne
    {
        return $this->hasOne(Screen::class);
    }

    /**
     * Get the workspace this conversation belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function startProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'last_activity_at' => now(),
        ]);
    }

    public function completeProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_IDLE,
            'last_activity_at' => now(),
        ]);

        // Session timestamp: update (conversation activity)
        $this->screen?->session?->touch();
    }

    public function markFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'last_activity_at' => now(),
        ]);

        // Session timestamp: update (conversation activity)
        $this->screen?->session?->touch();
    }

    public function archive(): void
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);
    }

    public function unarchive(): void
    {
        $this->update(['status' => self::STATUS_IDLE]);
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function addTokenUsage(int $inputTokens, int $outputTokens): void
    {
        $this->increment('total_input_tokens', $inputTokens);
        $this->increment('total_output_tokens', $outputTokens);
    }

    /**
     * Update context usage after an assistant response.
     *
     * Context estimate = input_tokens + output_tokens
     * This slightly overestimates because thinking tokens (included in output)
     * are stripped from previous turns. But it's safer to overestimate.
     *
     * @param int $inputTokens The total input tokens from the response.
     * @param int $outputTokens The output tokens (includes thinking).
     */
    public function updateContextUsage(int $inputTokens, int $outputTokens = 0): void
    {
        $this->update(['last_context_tokens' => $inputTokens + $outputTokens]);
    }

    /**
     * Recompute last_context_tokens from the latest assistant message (excludes cache tokens).
     * Fixes inflated values stored before cache was excluded from context tracking.
     *
     * Skips the write when the stored value already matches, since this runs on every
     * conversation read and would otherwise produce one UPDATE per page load.
     */
    public function refreshLastContextTokensFromMessages(): void
    {
        $lastAssistant = $this->messages()
            ->where('role', Message::ROLE_ASSISTANT)
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->first();

        if (!$lastAssistant) {
            return;
        }

        $input = (int) ($lastAssistant->input_tokens ?? 0);
        $output = (int) ($lastAssistant->output_tokens ?? 0);
        $cacheCreate = (int) ($lastAssistant->cache_creation_tokens ?? 0);
        $cacheRead = (int) ($lastAssistant->cache_read_tokens ?? 0);
        $ctxInput = max(0, $input - $cacheCreate - $cacheRead);
        $newTotal = $ctxInput + $output;

        if ((int) ($this->last_context_tokens ?? 0) === $newTotal) {
            return;
        }

        $this->updateContextUsage($ctxInput, $output);
    }

    /**
     * Update the cached context window size for the current model.
     *
     * Called when model changes or conversation is created.
     */
    public function updateContextWindowSize(int $contextWindow): void
    {
        $this->update(['context_window_size' => $contextWindow]);
    }

    /**
     * Ensure context_window_size is set (required for context % UI and usage events).
     *
     * Safe to call repeatedly. Uses agent extended_context, provider model config,
     * or cursor_agent fallback (200K) when the model is not in static config.
     */
    public function ensureContextWindowSize(): void
    {
        if ($this->context_window_size) {
            return;
        }

        try {
            $this->loadMissing('agent');
            $models = app(\App\Services\ModelRepository::class);

            if ($this->agent?->extended_context) {
                $maxContextWindow = $models->getMaxContextWindow($this->model);
                if ($maxContextWindow > 0) {
                    $this->updateContextWindowSize($maxContextWindow);

                    return;
                }
            }

            $provider = app(\App\Services\ProviderFactory::class)->make($this->provider_type);
            $contextWindow = $provider->getContextWindow($this->model);
            $this->updateContextWindowSize($contextWindow);
        } catch (\Throwable $e) {
            if ($this->provider_type === 'cursor_agent') {
                $this->updateContextWindowSize(200000);

                return;
            }

            \Illuminate\Support\Facades\Log::debug('Conversation: Failed to ensure context window size', [
                'conversation_uuid' => $this->uuid,
                'model' => $this->model,
                'provider' => $this->provider_type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get context usage percentage (0-100).
     *
     * Returns null if context info not available.
     */
    public function getContextUsagePercentage(): ?float
    {
        if ($this->last_context_tokens === null || $this->context_window_size === null || $this->context_window_size === 0) {
            return null;
        }

        return min(100, ($this->last_context_tokens / $this->context_window_size) * 100);
    }

    /**
     * Get context usage warning level.
     *
     * @return string|null 'safe' (0-74%), 'warning' (75-89%), 'danger' (90-100%), or null if unavailable
     */
    public function getContextWarningLevel(): ?string
    {
        $percentage = $this->getContextUsagePercentage();

        if ($percentage === null) {
            return null;
        }

        if ($percentage >= 90) {
            return 'danger';
        }
        if ($percentage >= 75) {
            return 'warning';
        }

        return 'safe';
    }

    /**
     * Get the next sequence number for a message in this conversation.
     *
     * Uses a database transaction with row locking to prevent race conditions
     * when multiple messages are created concurrently for the same conversation.
     *
     * LIMITATION: The sequence is calculated inside a transaction, but the actual
     * message insert happens outside this method (by the caller). This means the
     * lock is released before the insert completes. While this creates a potential
     * race condition window, the unique constraint on (conversation_id, sequence)
     * will catch any duplicate sequences and cause an error. For typical usage
     * patterns (single client per conversation), this is sufficient.
     */
    public function getNextSequence(): int
    {
        return DB::transaction(function () {
            // Lock the conversation row to prevent concurrent sequence assignment
            DB::table('conversations')
                ->where('id', $this->id)
                ->lockForUpdate()
                ->first();

            // Get the max sequence within the transaction
            return ($this->messages()->max('sequence') ?? 0) + 1;
        });
    }

    public function scopeActive($query)
    {
        // Include STATUS_FAILED so users can continue conversations after errors
        return $query->whereIn('status', [self::STATUS_IDLE, self::STATUS_PROCESSING, self::STATUS_FAILED]);
    }

    public function scopeArchived($query)
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    public function scopeForProvider($query, string $providerType)
    {
        return $query->where('provider_type', $providerType);
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace($query, string $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Get provider-specific reasoning configuration.
     *
     * Returns reasoning settings stored in the unified reasoning_config JSON column.
     * These settings are copied from the agent at conversation creation time
     * (see ConversationController::store) and remain fixed for the conversation's lifetime.
     *
     * This method does NOT check the agent relationship - it only reads the conversation's
     * own fields. This is intentional as conversations can exist without agents (legacy mode)
     * and conversation settings should not change if the agent is modified later.
     *
     * Provider-specific defaults:
     * - Anthropic: budget_tokens (explicit token allocation)
     * - OpenAI: effort (none/low/medium/high)
     * - OpenAI Compatible: effort (none/low/medium/high) - may be ignored by some servers
     * - Claude Code: thinking_tokens (via MAX_THINKING_TOKENS env var)
     * - Codex: effort (minimal/low/medium/high/xhigh) - for reasoning model depth
     */
    public function getReasoningConfig(): array
    {
        $config = $this->reasoning_config ?? [];

        // Apply provider-specific defaults
        return match ($this->provider_type) {
            'anthropic' => array_merge(['budget_tokens' => 0], $config),
            'openai' => array_merge(['effort' => 'none'], $config),
            'openai_compatible' => array_merge(['effort' => 'none'], $config),
            'claude_code' => array_merge(['thinking_tokens' => 0], $config),
            'codex' => array_merge(['effort' => 'minimal'], $config),
            'cursor_agent' => array_merge(['effort' => 'high', 'thinking' => true, 'fast' => false], $config),
            default => $config,
        };
    }

    /**
     * Update the reasoning config JSON column.
     * Merges with existing config (does not replace entirely).
     */
    public function updateReasoningConfig(array $config): void
    {
        $existing = $this->reasoning_config ?? [];
        $this->update([
            'reasoning_config' => array_merge($existing, $config),
        ]);
    }
}
