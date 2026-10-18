<?php

namespace App\Services;

use App\Jobs\ProcessConversationStream;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\SubAgentTask;
use App\Tools\ExecutionContext;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Encapsulates the logic for spawning a sub-agent conversation.
 *
 * Used by both SubAgentTool (generic slug-based dispatch) and AgentTool
 * (per-agent native tool calls). Supports foreground and background modes,
 * as well as resuming an existing conversation.
 */
class SubAgentRunner
{
    /**
     * Optional hook called as soon as a task exists, before a foreground wait.
     * The CLI uses it to print the task id up front, so it survives the wait
     * being killed (Bash timeout, or the parent interrupted by a queued message).
     *
     * @var (callable(SubAgentTask, Conversation): void)|null
     */
    public $onTaskStarted = null;

    public function __construct(
        private readonly ConversationFactory $factory,
        private readonly StreamManager $streamManager,
    ) {}

    /**
     * Run an agent with the given prompt.
     *
     * @param Agent           $agent          The agent to spawn
     * @param string          $prompt         The task/prompt to send
     * @param bool            $isBackground   Return immediately (background) or wait (foreground)
     * @param ExecutionContext $context        Caller's execution context
     * @param string|null     $conversationId Existing conversation UUID to resume (optional)
     */
    public function run(
        Agent $agent,
        string $prompt,
        bool $isBackground,
        ExecutionContext $context,
        ?string $conversationId = null,
    ): ToolResult {
        $workspace = $context->getWorkspace() ?? $agent->workspace;
        $workspaceId = $workspace?->id;
        $workingDirectory = $context->workingDirectory;

        try {
            if ($conversationId !== null) {
                return $this->resume($agent, $prompt, $isBackground, $context, $conversationId, $workspaceId);
            }

            return $this->spawn($agent, $prompt, $isBackground, $context, $workspaceId, $workingDirectory);
        } catch (\Throwable $e) {
            Log::error('SubAgentRunner: failed to start sub-agent', [
                'agent' => $agent->slug,
                'error' => $e->getMessage(),
            ]);
            return ToolResult::error('Failed to start sub-agent: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function spawn(
        Agent $agent,
        string $prompt,
        bool $isBackground,
        ExecutionContext $context,
        ?string $workspaceId,
        string $workingDirectory,
    ): ToolResult {
        [$conversation, $task] = DB::transaction(function () use ($agent, $workingDirectory, $workspaceId, $prompt, $context, $isBackground) {
            $conversation = $this->factory->createFromAgent(
                $agent,
                $workingDirectory,
                $workspaceId,
                'Subagent: ' . Str::limit($prompt, 60)
            );

            $task = SubAgentTask::create([
                'parent_conversation_uuid' => $context->conversationUuid,
                'child_conversation_uuid' => $conversation->uuid,
                'agent_id' => $agent->id,
                'prompt' => $prompt,
                'is_background' => $isBackground,
            ]);

            return [$conversation, $task];
        });

        // Initialize the Redis stream (outside transaction)
        $this->streamManager->startStream($conversation->uuid, [
            'model' => $conversation->model,
            'provider' => $conversation->provider_type,
            'subagent_task_id' => $task->id,
        ]);

        // Mark as processing BEFORE dispatching so waitForCompletion doesn't see
        // the initial 'idle' status and return prematurely before the job starts.
        $conversation->startProcessing();

        ProcessConversationStream::dispatch($conversation->uuid, $prompt);
        $this->notifyStarted($task, $conversation);

        Log::info('SubAgentRunner: task started', [
            'task_id' => $task->id,
            'parent_conversation' => $context->conversationUuid,
            'child_conversation' => $conversation->uuid,
            'agent' => $agent->slug,
            'background' => $isBackground,
        ]);

        if ($isBackground) {
            $jsonOutput = json_encode([
                'task_id' => $task->id,
                'conversation_id' => $conversation->uuid,
                'status' => 'running',
                'agent' => $agent->slug,
                'provider' => $agent->provider,
                'model' => $conversation->model,
                'message' => "Background sub-agent started. Its result is delivered to this conversation automatically as a <subagent-notification> message when it finishes (also if your turn has ended by then). No need to poll; use SubAgentOutput with task_id '{$task->id}' only to peek early. Use SubAgentCancel to stop it.",
            ], JSON_PRETTY_PRINT);

            $endTurnMessage = "Background sub-agent started (task `{$task->id}`, agent `{$agent->slug}`). "
                . "Its result will be delivered to this conversation automatically when it finishes.";

            return ToolResult::successEndTurn($jsonOutput, $endTurnMessage);
        }

        return $this->waitForCompletion($task, $conversation);
    }

    /**
     * Resume an existing conversation by adding a new user message.
     */
    private function resume(
        Agent $agent,
        string $prompt,
        bool $isBackground,
        ExecutionContext $context,
        string $conversationId,
        ?string $workspaceId,
    ): ToolResult {
        $conversation = Conversation::where('uuid', $conversationId)
            ->where('agent_id', $agent->id)
            ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
            ->first();

        if (!$conversation) {
            return ToolResult::error("Conversation '{$conversationId}' not found, not accessible, or belongs to a different agent.");
        }

        // Verify the conversation was originally spawned by this parent
        $isOwnedByParent = SubAgentTask::where('parent_conversation_uuid', $context->conversationUuid)
            ->where('child_conversation_uuid', $conversation->uuid)
            ->where('agent_id', $agent->id)
            ->exists();

        if (!$isOwnedByParent) {
            return ToolResult::error("Conversation '{$conversationId}' was not spawned by this conversation.");
        }

        if ($conversation->status === Conversation::STATUS_PROCESSING) {
            return ToolResult::error("Conversation '{$conversationId}' is still running. Wait for it to complete before resuming.");
        }

        // Create a new task linked to this resumed conversation
        $task = SubAgentTask::create([
            'parent_conversation_uuid' => $context->conversationUuid,
            'child_conversation_uuid' => $conversation->uuid,
            'agent_id' => $agent->id,
            'prompt' => $prompt,
            'is_background' => $isBackground,
        ]);

        // Re-initialise the stream for this turn
        $this->streamManager->startStream($conversation->uuid, [
            'model' => $conversation->model,
            'provider' => $conversation->provider_type,
            'subagent_task_id' => $task->id,
        ]);

        // Mark as processing before dispatch to prevent premature idle detection
        $conversation->startProcessing();

        ProcessConversationStream::dispatch($conversation->uuid, $prompt);
        $this->notifyStarted($task, $conversation);

        Log::info('SubAgentRunner: conversation resumed', [
            'task_id' => $task->id,
            'parent_conversation' => $context->conversationUuid,
            'child_conversation' => $conversation->uuid,
            'agent' => $agent->slug,
            'background' => $isBackground,
        ]);

        if ($isBackground) {
            return ToolResult::success(json_encode([
                'task_id' => $task->id,
                'conversation_id' => $conversation->uuid,
                'status' => 'running',
                'agent' => $agent->slug,
                'message' => "Resumed conversation. Its result is delivered to this conversation automatically when it finishes.",
            ], JSON_PRETTY_PRINT));
        }

        return $this->waitForCompletion($task, $conversation);
    }

    private function notifyStarted(SubAgentTask $task, Conversation $conversation): void
    {
        if ($this->onTaskStarted === null) {
            return;
        }
        try {
            ($this->onTaskStarted)($task, $conversation);
        } catch (\Throwable $e) {
            Log::warning('SubAgentRunner: onTaskStarted hook failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Poll the conversation until it reaches a terminal state.
     *
     * There is no hard limit by default (config ai.subagent.foreground_max_wait_seconds,
     * 0 = unlimited). Whatever ends the wait early (a Bash timeout in the calling CLI, a
     * queued user message that interrupts the parent, the optional limit) does NOT lose the
     * result: if nobody claims it here, NotifyParentOfSubAgentCompletion pushes it into
     * the parent conversation when the sub-agent finishes.
     */
    private function waitForCompletion(SubAgentTask $task, Conversation $conversation): ToolResult
    {
        $maxWaitSeconds = (int) config('ai.subagent.foreground_max_wait_seconds', 0);
        $pollIntervalMicroseconds = 1_000_000; // 1 second
        $startTime = time();

        while ($maxWaitSeconds <= 0 || time() - $startTime < $maxWaitSeconds) {
            $conversation->refresh();

            $isDone = in_array($conversation->status, [Conversation::STATUS_IDLE, Conversation::STATUS_ARCHIVED], true);
            $isFailed = $conversation->status === Conversation::STATUS_FAILED;

            if ($isDone || $isFailed) {
                // Claim the result so the parent is not notified a second time.
                // Losing the claim only means a notification was already sent.
                $task->claimDelivery('result_delivered_at');
                $task->unsetRelation('childConversation');
            }

            if ($isDone) {
                $output = $task->collectOutput();
                return ToolResult::success(json_encode([
                    'task_id' => $task->id,
                    'conversation_id' => $conversation->uuid,
                    'status' => 'completed',
                    'output' => $output,
                ], JSON_PRETTY_PRINT));
            }

            if ($isFailed) {
                $error = $task->getError();
                return ToolResult::error("Sub-agent task '{$task->id}' failed: {$error}");
            }

            usleep($pollIntervalMicroseconds);
        }

        return ToolResult::success(json_encode([
            'task_id' => $task->id,
            'conversation_id' => $conversation->uuid,
            'status' => 'running',
            'message' => "Stopped waiting after {$maxWaitSeconds} seconds; the sub-agent keeps running. "
                . 'Its result will be delivered to this conversation automatically when it finishes.',
        ], JSON_PRETTY_PRINT));
    }
}
