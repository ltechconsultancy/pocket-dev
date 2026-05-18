<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Convert CLI provider message tokens from cumulative context values to
 * per-turn deltas. Also fix conversations.total_input/output_tokens which
 * were incorrectly accumulated from cumulative values.
 *
 * This migration processes each CLI conversation's assistant messages in
 * sequence order, computing delta = max(0, current - previous) for each.
 * Negative deltas (from compaction) are clamped to 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Process in chunks by conversation to avoid memory issues
        $cliConversationIds = DB::table('conversations')
            ->whereIn('provider_type', ['claude_code', 'codex', 'cursor_agent'])
            ->pluck('id');

        $totalConverted = 0;

        foreach ($cliConversationIds->chunk(100) as $chunk) {
            foreach ($chunk as $conversationId) {
                $totalConverted += $this->convertConversation($conversationId);
            }
        }

        Log::info("CLI token migration: converted {$totalConverted} messages across {$cliConversationIds->count()} conversations");
    }

    private function convertConversation(int $conversationId): int
    {
        $messages = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotNull('input_tokens')
            ->orderBy('sequence', 'asc')
            ->select('id', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens')
            ->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        $prevInput = 0;
        $prevOutput = 0;
        $prevCacheRead = 0;
        $prevCacheCreation = 0;
        $totalDeltaInput = 0;
        $totalDeltaOutput = 0;
        $count = 0;

        foreach ($messages as $msg) {
            $deltaInput = max(0, ($msg->input_tokens ?? 0) - $prevInput);
            $deltaOutput = max(0, ($msg->output_tokens ?? 0) - $prevOutput);
            $deltaCacheRead = max(0, ($msg->cache_read_tokens ?? 0) - $prevCacheRead);
            $deltaCacheCreation = max(0, ($msg->cache_creation_tokens ?? 0) - $prevCacheCreation);

            $prevInput = $msg->input_tokens ?? 0;
            $prevOutput = $msg->output_tokens ?? 0;
            $prevCacheRead = $msg->cache_read_tokens ?? 0;
            $prevCacheCreation = $msg->cache_creation_tokens ?? 0;

            // Update message with delta values and null out unreliable cost
            DB::table('messages')
                ->where('id', $msg->id)
                ->update([
                    'input_tokens' => $deltaInput,
                    'output_tokens' => $deltaOutput,
                    'cache_read_tokens' => $deltaCacheRead > 0 ? $deltaCacheRead : null,
                    'cache_creation_tokens' => $deltaCacheCreation > 0 ? $deltaCacheCreation : null,
                    'cost' => null, // Was computed from cumulative values, unreliable
                ]);

            $totalDeltaInput += $deltaInput;
            $totalDeltaOutput += $deltaOutput;
            $count++;
        }

        // Fix conversation totals
        DB::table('conversations')
            ->where('id', $conversationId)
            ->update([
                'total_input_tokens' => $totalDeltaInput,
                'total_output_tokens' => $totalDeltaOutput,
            ]);

        return $count;
    }

    public function down(): void
    {
        // Not reversible: we'd need to store original cumulative values somewhere.
        // The backfill in the previous migration preserved last_reported_*_tokens
        // which could be used to verify correctness, but full reversal isn't practical.
        Log::warning('CLI token delta conversion cannot be automatically reversed');
    }
};
