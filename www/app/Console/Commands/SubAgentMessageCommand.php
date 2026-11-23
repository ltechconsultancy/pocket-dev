<?php

namespace App\Console\Commands;

use App\Models\SubAgentTask;
use App\Services\CursorFollowUpPrompt;
use App\Services\CursorFollowUpQueue;
use App\Services\StreamManager;
use Illuminate\Console\Command;

/**
 * Send a message to a running sub-agent mid-stream.
 *
 * Uses the same follow-up queue as the chat UI: Claude Code and Cursor Agent
 * get it as soon as no tool is in flight ("This prompt was added mid stream: ...");
 * OpenCode gets it right after its current answer.
 */
class SubAgentMessageCommand extends Command
{
    protected $signature = 'subagent:message
        {--task-id= : The task UUID returned by subagent:run}
        {--prompt= : The message to send to the running sub-agent}';

    protected $description = 'Send a message to a running sub-agent (mid-stream)';

    public function handle(CursorFollowUpQueue $queue, StreamManager $streamManager): int
    {
        $taskId = trim((string) $this->option('task-id'));
        $prompt = trim((string) $this->option('prompt'));

        if ($taskId === '' || $prompt === '') {
            return $this->fail_('--task-id and --prompt are required');
        }

        $task = SubAgentTask::with('childConversation')->find($taskId);
        if (!$task) {
            return $this->fail_("Sub-agent task '{$taskId}' not found.");
        }

        // Only the conversation that spawned the sub-agent may message it.
        $parentUuid = getenv('POCKETDEV_CONVERSATION_UUID') ?: null;
        if ($parentUuid && $task->parent_conversation_uuid !== $parentUuid) {
            return $this->fail_("Task '{$taskId}' was not spawned by this conversation.");
        }

        $conversation = $task->childConversation;
        if (!$conversation) {
            return $this->fail_("Sub-agent task '{$taskId}' has no conversation yet. Try again in a moment.");
        }

        if ($task->cancelled_at) {
            return $this->fail_("Task '{$taskId}' was cancelled.");
        }

        if (!CursorFollowUpPrompt::supports((string) $conversation->provider_type)) {
            return $this->fail_("Provider '{$conversation->provider_type}' does not support mid-stream messages.");
        }

        if (!$streamManager->isStreaming($conversation->uuid)) {
            return $this->fail_("Task '{$taskId}' is not running (status: {$task->getStatus()}). "
                . 'Start a new sub-agent instead.');
        }

        $item = $queue->enqueue($conversation->uuid, $prompt);

        $interrupts = CursorFollowUpPrompt::interruptsRun((string) $conversation->provider_type);

        $this->outputJson([
            'success' => true,
            'task_id' => $task->id,
            'conversation_uuid' => $conversation->uuid,
            'queued' => $item,
            'pending_messages' => count($queue->list($conversation->uuid)),
            'delivery' => $interrupts
                ? 'Delivered as soon as the sub-agent has no tool running.'
                : 'Delivered right after the sub-agent finishes its current answer.',
        ]);

        return Command::SUCCESS;
    }

    private function fail_(string $message): int
    {
        $this->outputJson(['success' => false, 'error' => $message]);

        return Command::FAILURE;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
