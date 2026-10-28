<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Todo app: a task (no project = Inbox). A task with parent_id is a subtask
 * (one level deep). Can link to a PocketDev session and an App Tray app.
 */
class TodoTask extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'project_id', 'parent_id', 'title', 'notes', 'deadline', 'done_at', 'done_by',
        'created_by', 'session_id', 'app_tray_item_id', 'sort_order',
    ];

    protected $casts = [
        'deadline' => 'date:Y-m-d',
        'done_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(TodoProject::class, 'project_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TodoTask::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(TodoTask::class, 'parent_id');
    }

    public function toTodoArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'deadline' => $this->deadline?->format('Y-m-d'),
            'done' => $this->done_at !== null,
            'done_at' => $this->done_at?->toIso8601String(),
            'done_by' => $this->done_by,
            'created_by' => $this->created_by,
            'session_id' => $this->session_id,
            'app_tray_item_id' => $this->app_tray_item_id,
            'sort_order' => $this->sort_order,
        ];
    }
}
