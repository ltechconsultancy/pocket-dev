<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Todo app: a project (optionally of a client) holds tasks.
 */
class TodoProject extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id', 'name', 'notes', 'deadline', 'done_at', 'done_by',
        'created_by', 'session_id', 'app_tray_item_id', 'sort_order',
    ];

    protected $casts = [
        'deadline' => 'date:Y-m-d',
        'done_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(TodoClient::class, 'client_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(TodoTask::class, 'project_id');
    }

    public function toTodoArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
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
