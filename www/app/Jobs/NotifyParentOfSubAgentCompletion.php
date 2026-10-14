<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\SubAgentTask;
use App\Services\CursorFollowUpPrompt;
use App\Services\CursorFollowUpQueue;
use App\Services\StreamManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Push a finished sub-agent's result into its parent conversation.
 *
 * This is what makes PocketDev sub-agents behave like Claude Code's built-in
 * background agents, but without dying when the parent's turn ends:
 *
 * - Parent idle   → start a new parent turn with the result (the agent wakes up).
 * - Parent busy   → queue the result as a mid-stream follow-up; it is injected at
 *                   the next safe point (no tool running), exactly like a message
 *                   the user queues. Nothing in flight gets killed.
 *
 * Foreground tasks are only notified when nobody received the result directly,
 * i.e. the `pd subagent:run` wait was killed (Bash timeout, or the parent was
 * interrupted by a queued message). SubAgentTask::claimDelivery() guarantees the
 * parent gets the result exactly once.
 */
class NotifyParentOfSubAgentCompletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Delay before notifying, so a live foreground waiter (polls every 1s) wins. */
    public const DELAY_SECONDS = 8;

    /** Max characters of sub-agent output inlined in the notification. */
    private const MAX_OUTPUT_CHARS = 30000;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public string $childConversationUuid) {}

    /**
     * Called when a conversation leaves the processing state. Cheap no-op for
     * conversations that are not sub-agents.
     */
    public static function dispatchIfSubAgent(Conversation $conversation): void
    {
        try {
            $hasPending = SubAgentTask::where('child_conversation_uuid', $conversation->uuid)
                ->whereNotNull('parent_conversation_uuid')
                ->whereNull('result_delivered_at')
                ->whereNull('parent_notified_at')
                ->whereNull('cancelled_at')
                ->exists();

            if ($hasPending) {
                static::dispatch($conversation->uuid)->delay(now()->addSeconds(self::DELAY_SECONDS));
            }
        } catch (\Throwable $e) {
            // Never let notification bookkeeping break the conversation itself
            Log::warning('NotifyParentOfSubAgentCompletion: dispatch check failed', [
                'conversation' => $conversation->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(StreamManager $streamManager, CursorFollowUpQueue $followUpQueue): void
    {
        $child = Conversation::where('uuid', $this->childConversationUuid)->first();
        if (!$child) {
            return;
        }

        // The child was resumed or is mid follow-up: it will notify again when it settles
        if ($child->status === Conversation::STATUS_PROCESSING || $streamManager->isStreaming($child->uuid)) {
            return;
        }

        // Latest undelivered task for this child (a child can be resumed several times)
        $task = SubAgentTask::with('agent')
            ->where('child_conversation_uuid', $child->uuid)
            ->whereNotNull('parent_conversation_uuid')
            ->whereNull('result_delivered_at')
            ->whereNull('parent_notified_at')
            ->whereNull('cancelled_at')
            ->orderByDesc('created_at')
            ->first();

        if (!$task) {
            return;
        }

        $parent = Conversation::where('uuid', $task->parent_conversation_uuid)->first();
        if (!$parent || $parent->status === Conversation::STATUS_ARCHIVED) {
            return;
        }

        // API providers have no mid-stream queue: try again once the parent's turn is over
        if (!CursorFollowUpPrompt::supports($parent->provider_type) && $streamManager->isStreaming($parent->uuid)) {
            static::dispatch($this->childConversationUuid)->delay(now()->addSeconds(15));
            return;
        }

        if (!$task->claimDelivery('parent_notified_at')) {
            return; // A foreground waiter (or a parallel job) already delivered it
        }

        // Older undelivered tasks of the same child are covered by this notification
        SubAgentTask::where('child_conversation_uuid', $child->uuid)
            ->whereKeyNot($task->id)
            ->whereNull('result_delivered_at')
            ->whereNull('parent_notified_at')
            ->update(['parent_notified_at' => now()]);

        $prompt = $this->buildNotification($task, $child);

        $this->deliver($parent, $prompt, $streamManager, $followUpQueue);

        Log::info('NotifyParentOfSubAgentCompletion: parent notified', [
            'task_id' => $task->id,
            'child' => $child->uuid,
            'parent' => $parent->uuid,
            'child_status' => $child->status,
        ]);
    }

    private function deliver(
        Conversation $parent,
        string $prompt,
        StreamManager $streamManager,
        CursorFollowUpQueue $followUpQueue,
    ): void {
        if ($streamManager->isStreaming($parent->uuid) && CursorFollowUpPrompt::supports($parent->provider_type)) {
            // Injected at the next safe point (no tool in flight) or right after the
            // current turn; same path as a message the user queues.
            $item = $followUpQueue->enqueue($parent->uuid, $prompt);

            // The parent may have finished between the check and the enqueue; then
            // nobody would drain the queue. Take the item back and start a turn.
            if ($streamManager->isStreaming($parent->uuid) || !$followUpQueue->remove($parent->uuid, $item['id'])) {
                return;
            }
        }

        $streamManager->startStream($parent->uuid, [
            'model' => $parent->model,
            'provider' => $parent->provider_type,
            'subagent_notification' => true,
        ]);
        $parent->startProcessing();

        ProcessConversationStream::dispatch($parent->uuid, $prompt);
    }

    private function buildNotification(SubAgentTask $task, Conversation $child): string
    {
        $failed = $child->status === Conversation::STATUS_FAILED;
        $agentSlug = $task->agent?->slug ?? 'unknown';
        $status = $failed ? 'failed' : 'completed';

        if ($failed) {
            $body = 'Error: ' . ($task->getError() ?? 'unknown error');
        } else {
            $output = trim($task->collectOutput());
            if ($output === '') {
                $output = '(the sub-agent produced no text output)';
            }
            if (mb_strlen($output) > self::MAX_OUTPUT_CHARS) {
                $output = '…' . mb_substr($output, -self::MAX_OUTPUT_CHARS)
                    . "\n\n[Output truncated to the last " . self::MAX_OUTPUT_CHARS . ' characters. '
                    . "Full output: pd subagent:output --task-id={$task->id}]";
            }
            $body = $output;
        }

        $promptPreview = Str::limit(preg_replace('/\s+/', ' ', $task->prompt), 160);

        return <<<TXT
<subagent-notification>
PocketDev sub-agent task {$task->id} ({$agentSlug}) {$status}.
Task: {$promptPreview}
Conversation: {$child->uuid}

{$body}
</subagent-notification>

This is an automatic notification, not a message from the user. Continue the work it belongs to. Other sub-agents you started may still be running; you will get a separate notification for each.
TXT;
    }
}
