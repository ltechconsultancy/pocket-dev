<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CLI providers (Claude Code, Codex, Cursor Agent) report cumulative context
 * window tokens per turn, not per-message deltas. We need to track the last
 * reported cumulative value so we can calculate the actual per-turn delta
 * before storing it in messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('last_reported_input_tokens')->nullable()->after('total_output_tokens');
            $table->unsignedBigInteger('last_reported_output_tokens')->nullable()->after('last_reported_input_tokens');
        });

        // Backfill: For existing CLI conversations, set to the last assistant
        // message's input/output_tokens (which are currently cumulative values).
        DB::statement("
            UPDATE conversations c
            SET last_reported_input_tokens = sub.input_tokens,
                last_reported_output_tokens = sub.output_tokens
            FROM (
                SELECT DISTINCT ON (m.conversation_id)
                    m.conversation_id,
                    m.input_tokens,
                    m.output_tokens
                FROM messages m
                JOIN conversations conv ON conv.id = m.conversation_id
                WHERE conv.provider_type IN ('claude_code', 'codex', 'cursor_agent')
                  AND m.role = 'assistant'
                  AND m.input_tokens IS NOT NULL
                  AND m.input_tokens > 0
                ORDER BY m.conversation_id, m.sequence DESC
            ) sub
            WHERE c.id = sub.conversation_id
        ");
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_reported_input_tokens', 'last_reported_output_tokens']);
        });
    }
};
