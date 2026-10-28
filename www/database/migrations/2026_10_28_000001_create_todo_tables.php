<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple todo app: clients > projects > tasks > subtasks.
 * Both the user (UI) and agents (`pd todo`) add and check off items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('todo_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Deleting a client keeps its projects ("Zonder klant")
            $table->foreignUuid('client_id')->nullable()->constrained('todo_clients')->nullOnDelete();
            $table->string('name', 150);
            $table->text('notes')->nullable();
            $table->date('deadline')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('done_by', 10)->nullable(); // user | ai
            $table->string('created_by', 10)->default('user');
            $table->foreignUuid('session_id')->nullable()->constrained('pocketdev_sessions')->nullOnDelete();
            $table->foreignUuid('app_tray_item_id')->nullable()->constrained('app_tray_items')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('todo_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No project = Inbox. Deleting a project deletes its tasks.
            $table->foreignUuid('project_id')->nullable()->constrained('todo_projects')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable(); // set = subtask (one level deep)
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->date('deadline')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('done_by', 10)->nullable(); // user | ai
            $table->string('created_by', 10)->default('user');
            $table->foreignUuid('session_id')->nullable()->constrained('pocketdev_sessions')->nullOnDelete();
            $table->foreignUuid('app_tray_item_id')->nullable()->constrained('app_tray_items')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'parent_id']);
        });

        Schema::table('todo_tasks', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('todo_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_tasks');
        Schema::dropIfExists('todo_projects');
        Schema::dropIfExists('todo_clients');
    }
};
