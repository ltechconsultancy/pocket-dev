<?php

namespace App\Tools;

use App\Models\Agent;
use App\Services\SubAgentRunner;
use Illuminate\Support\Str;

class SubAgentTool extends Tool
{
    /**
     * Optional hook passed to SubAgentRunner; called once the task exists.
     *
     * @var (callable(\App\Models\SubAgentTask, \App\Models\Conversation): void)|null
     */
    public $onTaskStarted = null;

    public string $name = 'SubAgent';

    public string $description = 'Spawn a child PocketDev agent to handle a task. Supports cross-provider delegation (e.g. Claude Code can call Codex and vice versa).';

    public string $category = 'custom';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'agent' => [
                'type' => 'string',
                'description' => 'Agent slug or UUID. See "Available Agents" in the system prompt for valid values.',
            ],
            'prompt' => [
                'type' => 'string',
                'description' => 'The task/prompt to send to the sub-agent.',
            ],
            'background' => [
                'type' => 'boolean',
                'description' => 'If true, return immediately with a task_id. If false (default), wait for the sub-agent to complete and return its output.',
            ],
            'conversation_id' => [
                'type' => 'string',
                'description' => 'Optional: UUID of a previous conversation with this agent to resume. When provided, prompt is appended as the next user message.',
            ],
        ],
        'required' => ['agent', 'prompt'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Spawn a child PocketDev agent to handle a task using any configured provider. This enables cross-provider delegation: Claude Code can call a Codex agent, Codex can call a Claude Code agent, etc.

## When to Use
- Delegate specialized tasks to a different AI model or provider
- Run multiple tasks in parallel using background mode
- Break complex work into sub-tasks handled by purpose-built agents
- For agents with `expose_as_tool = true`, prefer calling them directly by name (e.g. `agent_code_reviewer`) instead of using SubAgent

## Foreground Mode (default)
Blocks until the sub-agent completes and returns its full output. There is no time limit on the wait. Use for short tasks where you need the result before continuing.

## Background Mode (preferred for anything longer than a few minutes)
Returns immediately with a task_id. When the sub-agent finishes, its result is delivered to your conversation automatically as a `<subagent-notification>` message, even if your turn has already ended (you are woken up) or you are busy (it is queued and injected at the next safe point). So: start the agents, tell the user what is running, and end your turn. Do not poll in a loop. Ideal for parallelism.

Sub-agents are independent PocketDev conversations: they keep running when your turn ends and when the user sends or queues a message. Claude Code's built-in Task/Agent tool does NOT survive that (it dies with your turn), so never use it for long work in PocketDev.

## Resuming a Conversation
Pass `conversation_id` from a previous response to continue from where you left off. The sub-agent will see the full conversation history.

## Parameters
- agent: Agent slug or UUID (see "Available Agents" section in this prompt)
- prompt: The task/prompt to send. Be specific and self-contained — the sub-agent has no context from your conversation
- background: Optional boolean (default: false). Set to true for background execution
- conversation_id: Optional UUID to resume an existing sub-agent conversation

## Important Notes
- The sub-agent starts a fresh conversation (unless conversation_id is provided)
- Write self-contained prompts with all necessary context
- The sub-agent shares your working directory and workspace
- Foreground waits have no time limit. If the wait itself is cut off (Bash timeout, or a queued user message interrupts you), the sub-agent keeps running and its result is delivered to you automatically as a notification
- For a foreground call via Bash, pass a long Bash timeout (e.g. 3600000 ms) or use background mode
- Background results arrive automatically; SubAgentOutput is only for peeking at a running task
- To stop a running background task, use SubAgentCancel with its task_id
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
# Foreground: wait for result
pd subagent:run --agent=codex-default --prompt="List all TODO comments in the codebase"

# Background: get task ID immediately
pd subagent:run --agent=claude-code-default --prompt="Refactor the auth module" --background

# The result arrives automatically as a <subagent-notification> message.
# Optional: peek at a running task
pd subagent:output --task-id=<uuid-from-above>
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "agent": "codex-default",
  "prompt": "Find and fix all TypeScript type errors",
  "background": true
}
```
API;

    public function getArtisanCommand(): ?string
    {
        return 'subagent:run';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $agentIdentifier = trim($input['agent'] ?? '');
        $prompt = trim($input['prompt'] ?? '');
        $isBackground = (bool) ($input['background'] ?? false);
        $conversationId = isset($input['conversation_id']) ? trim($input['conversation_id']) : null;

        if (empty($agentIdentifier)) {
            return ToolResult::error('agent is required. See "Available Agents" in the system prompt.');
        }
        if (empty($prompt)) {
            return ToolResult::error('prompt is required');
        }

        // Scope agent resolution to the caller's workspace
        $workspace = $context->getWorkspace();
        $agentQuery = Agent::query()->where('enabled', true);
        if ($workspace) {
            $agentQuery->where('workspace_id', $workspace->id);
        }

        $agent = (clone $agentQuery)->where('slug', $agentIdentifier)->first();
        if (!$agent && Str::isUuid($agentIdentifier)) {
            $agent = (clone $agentQuery)->whereKey($agentIdentifier)->first();
        }

        if (!$agent) {
            return ToolResult::error("Agent '{$agentIdentifier}' not found. Use an agent slug or UUID from the Available Agents list.");
        }

        $runner = app(SubAgentRunner::class);
        $runner->onTaskStarted = $this->onTaskStarted;

        return $runner->run(
            $agent,
            $prompt,
            $isBackground,
            $context,
            $conversationId ?: null,
        );
    }
}
