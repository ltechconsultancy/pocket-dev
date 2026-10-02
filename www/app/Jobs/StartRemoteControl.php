<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ProviderFactory;
use App\Services\RemoteControlService;
use App\Services\SystemPromptBuilder;
use App\Services\ToolRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Starts Claude Code Remote Control for a conversation.
 *
 * Runs on the queue because the queue worker runs as the user that owns the
 * Claude OAuth login (PHP-FPM cannot read it).
 */
class StartRemoteControl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(public string $conversationUuid)
    {
    }

    public function handle(
        RemoteControlService $remoteControl,
        SystemPromptBuilder $systemPromptBuilder,
        ToolRegistry $toolRegistry,
        ProviderFactory $providerFactory,
    ): void {
        $conversation = Conversation::where('uuid', $this->conversationUuid)->first();
        if (!$conversation) {
            return;
        }

        $remoteControl->start($conversation, $systemPromptBuilder, $toolRegistry, $providerFactory);
    }
}
