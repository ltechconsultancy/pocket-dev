<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track whether a sub-agent's result reached its parent conversation.
 *
 * - result_delivered_at: a foreground `pd subagent:run` returned the output directly.
 * - parent_notified_at:  the result was pushed into the parent conversation as a
 *                        notification turn (background tasks, or foreground waits
 *                        that were killed by a timeout or a queued message).
 * - cancelled_at:        the parent cancelled the task; no notification is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subagent_tasks', function (Blueprint $table) {
            $table->timestamp('result_delivered_at')->nullable();
            $table->timestamp('parent_notified_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subagent_tasks', function (Blueprint $table) {
            $table->dropColumn(['result_delivered_at', 'parent_notified_at', 'cancelled_at']);
        });
    }
};
