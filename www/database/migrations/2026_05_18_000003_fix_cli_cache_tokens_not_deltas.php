<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fix cache token values for CLI provider messages.
 *
 * The previous migration (000002) incorrectly converted cache_read_tokens and
 * cache_creation_tokens to deltas. Unlike input_tokens and output_tokens (which
 * are cumulative context totals in CLI providers), cache tokens represent the
 * per-turn cache breakdown:
 *
 * - Anthropic API: input_tokens, cache_read, cache_creation are per-request
 *   and SEPARATE (total = sum of all three). Claude Code CLI accumulates
 *   input_tokens but cache tokens reflect the current turn's cache state.
 *
 * - OpenAI API: prompt_tokens is the total (including cached), and
 *   cached_tokens is a subset. Codex CLI similarly reports per-turn cache state.
 *
 * Since we can't recover the original per-turn cache values from deltas,
 * we null out the incorrectly converted cache tokens. Going forward,
 * ProcessConversationStream stores them correctly (absolute per-turn values).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Null out cache tokens for CLI messages that were incorrectly delta-converted.
        // We can't recover the originals, but at least we stop showing wrong data.
        // Future messages will have correct absolute cache values.
        $affected = DB::table('messages as m')
            ->join('conversations as c', 'm.conversation_id', '=', 'c.id')
            ->whereIn('c.provider_type', ['claude_code', 'codex', 'cursor_agent'])
            ->where('m.role', 'assistant')
            ->where(function ($q) {
                $q->whereNotNull('m.cache_read_tokens')
                  ->orWhereNotNull('m.cache_creation_tokens');
            })
            ->update([
                'm.cache_read_tokens' => null,
                'm.cache_creation_tokens' => null,
                'm.cost' => null,
            ]);

        Log::info("CLI cache token fix: nulled cache tokens on {$affected} messages");
    }

    public function down(): void
    {
        Log::warning('CLI cache token fix: cannot reverse (original values were lost in delta conversion)');
    }
};
