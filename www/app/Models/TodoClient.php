<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Todo app: a client groups projects. Managed in the UI and via `pd todo`.
 */
class TodoClient extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['name', 'notes', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function projects(): HasMany
    {
        return $this->hasMany(TodoProject::class, 'client_id');
    }

    public function toTodoArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
        ];
    }
}
