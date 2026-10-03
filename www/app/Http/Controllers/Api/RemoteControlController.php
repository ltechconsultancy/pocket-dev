<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\RemoteControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Toggle Claude Code Remote Control (claude.ai/code + Claude app) per conversation.
 */
class RemoteControlController extends Controller
{
    public function __construct(private RemoteControlService $remoteControl)
    {
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $this->remoteControl->ensureStarted($conversation);

        return response()->json($this->remoteControl->status($conversation));
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        if (!$this->remoteControl->isSupported($conversation)) {
            return response()->json([
                'error' => 'Remote Control is only available for Claude Code chats',
            ], 422);
        }

        if ($validated['enabled']) {
            $this->remoteControl->enable($conversation);
        } else {
            $this->remoteControl->disable($conversation);
        }

        return response()->json($this->remoteControl->status($conversation));
    }
}
