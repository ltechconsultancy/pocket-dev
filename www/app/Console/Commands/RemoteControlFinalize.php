<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\RemoteControlService;
use Illuminate\Console\Command;

/**
 * Called by the Remote Control wrapper after the Claude CLI exited:
 * imports messages sent from claude.ai / the Claude app into PocketDev.
 */
class RemoteControlFinalize extends Command
{
    protected $signature = 'remote-control:finalize {uuid}';

    protected $description = 'Import Remote Control messages into a PocketDev conversation after the session ended';

    public function handle(RemoteControlService $remoteControl): int
    {
        $conversation = Conversation::where('uuid', $this->argument('uuid'))->first();
        if (!$conversation) {
            $this->error('Conversation not found');
            return self::FAILURE;
        }

        $imported = $remoteControl->finalize($conversation);
        $this->info("Imported {$imported} message(s)");

        return self::SUCCESS;
    }
}
