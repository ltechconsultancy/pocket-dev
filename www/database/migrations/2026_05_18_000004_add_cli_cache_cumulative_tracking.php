<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add cumulative cache token tracking to conversations for CLI providers.
 *
 * Claude Code CLI reports cache_read_input_tokens as semi-cumulative (grows
 * with context, resets on compaction) and cache_creation as per-turn.
 * Codex reports cached_input_tokens as a subset of cumulative input_tokens.
 *
 * To compute correct per-turn cache deltas (needed for API-equivalent cost),
 * we track the last reported cumulative cache values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('last_reported_cache_read')->nullable()->after('last_reported_output_tokens');
            $table->unsignedBigInteger('last_reported_cache_creation')->nullable()->after('last_reported_cache_read');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_reported_cache_read', 'last_reported_cache_creation']);
        });
    }
};
