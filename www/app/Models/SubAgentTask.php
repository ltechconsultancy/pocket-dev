<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubAgentTask extends Model
{
    use HasUuids;

    protected $table = 'subagent_tasks';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'parent_conversation_uuid',
        'child_conversation_uuid',
        'agent_id',
        'prompt',
        'is_background',
        'result_delivered_at',
        'parent_notified_at',
        'cancelled_at',
    ];

    protected $casts = [
        'is_background' => 'boolean',
        'result_delivered_at' => 'datetime',
        'parent_notified_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function childConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'child_conversation_uuid', 'uuid');
    }

    public function parentConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'parent_conversation_uuid', 'uuid');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    // -------------------------------------------------------------------------
    // Derived methods (read from the child conversation live, no caching)
    // -------------------------------------------------------------------------

    /**
     * Atomically claim the right to hand this task's result to the parent.
     *
     * Exactly one of "foreground waiter returned it" or "notification pushed to the
     * parent" wins, so the parent never gets the same result twice and never zero times.
     *
     * @param 'result_delivered_at'|'parent_notified_at' $column
     */
    public function claimDelivery(string $column): bool
    {
        $claimed = static::query()
            ->whereKey($this->id)
            ->whereNull('result_delivered_at')
            ->whereNull('parent_notified_at')
            ->whereNull('cancelled_at')
            ->update([$column => now()]);

        return $claimed === 1;
    }

    /**
     * Get the current status of the subagent task.
     * Derived from the child conversation's status.
     */
    public function getStatus(): string
    {
        $conversation = $this->childConversation;
        if (!$conversation) {
            return 'pending';
        }
        return match ($conversation->status) {
            Conversation::STATUS_IDLE, Conversation::STATUS_ARCHIVED => 'completed',
            Conversation::STATUS_PROCESSING => 'running',
            Conversation::STATUS_FAILED => 'failed',
            default => 'pending',
        };
    }

    /**
     * Collect the output from all assistant messages in the child conversation.
     */
    public function collectOutput(): string
    {
        $conversation = $this->childConversation;
        if (!$conversation) {
            return '';
        }

        $messages = $conversation->messages()
            ->where('role', 'assistant')
            ->orderBy('sequence')
            ->get();

        $output = [];
        foreach ($messages as $message) {
            $text = trim($message->getTextContent());
            if ($text !== '') {
                $output[] = $text;
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * Get error message if the task failed.
     */
    public function getError(): ?string
    {
        $conversation = $this->childConversation;
        if (!$conversation || $conversation->status !== Conversation::STATUS_FAILED) {
            return null;
        }

        $errorMessage = $conversation->messages()
            ->where('role', 'error')
            // reorder(): the messages() relation already sorts by sequence ASC, which
            // would otherwise win over the DESC below and return the FIRST message
            ->reorder()
            ->orderByDesc('sequence')
            ->first();

        if ($errorMessage) {
            $content = $errorMessage->content;
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text') {
                        return $block['text'];
                    }
                }
            }
            if (is_string($content)) {
                return $content;
            }
        }

        return 'Task failed (no error details available)';
    }
}
