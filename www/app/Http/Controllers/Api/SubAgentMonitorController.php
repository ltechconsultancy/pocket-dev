<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\SubAgentTask;
use App\Services\SubAgentMonitor;
use App\Tools\ExecutionContext;
use App\Tools\SubAgentCancelTool;
use Illuminate\Http\JsonResponse;

/**
 * Sub-agents running for a session (pill + sheet above the chat input).
 */
class SubAgentMonitorController extends Controller
{
    public function index(Session $session, SubAgentMonitor $monitor): JsonResponse
    {
        return response()->json($monitor->forSession($session));
    }

    /**
     * Running sub-agents per session id and per chat tab (badges in the
     * session list and the tab bar).
     */
    public function running(SubAgentMonitor $monitor): JsonResponse
    {
        $counts = $monitor->runningCounts();

        return response()->json([
            'sessions' => (object) $counts['sessions'],
            'conversations' => (object) $counts['conversations'],
        ]);
    }

    /**
     * Full history (thinking, text, tool calls + results) for the viewer.
     */
    public function events(\Illuminate\Http\Request $request, SubAgentTask $task, SubAgentMonitor $monitor): JsonResponse
    {
        return response()->json($monitor->events($task, max(0, (int) $request->query('from', 0))));
    }

    /**
     * Stop a sub-agent from the UI (the user may stop any of them).
     */
    public function cancel(SubAgentTask $task): JsonResponse
    {
        $result = (new SubAgentCancelTool())->execute(['task_id' => $task->id], new ExecutionContext(base_path()));

        return response()->json([
            'ok' => !$result->isError(),
            'result' => json_decode((string) ($result->toArray()['output'] ?? ''), true),
        ], $result->isError() ? 422 : 200);
    }

    /**
     * History of one of Claude Code's own sub-agents (built-in Agent tool), for the viewer.
     */
    public function nativeEvents(\Illuminate\Http\Request $request, string $conversation, string $agentId, \App\Services\NativeSubAgentMonitor $monitor): JsonResponse
    {
        $chat = \App\Models\Conversation::where('uuid', $conversation)->firstOrFail();
        abort_unless(preg_match('/^[a-z0-9]{4,40}$/', $agentId) === 1, 404);

        return response()->json($monitor->events($chat, $agentId, max(0, (int) $request->query('from', 0))));
    }
}
