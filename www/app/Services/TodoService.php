<?php

namespace App\Services;

use App\Models\AppTrayItem;
use App\Models\Session;
use App\Models\TodoClient;
use App\Models\TodoProject;
use App\Models\TodoTask;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Shared logic for the todo app (UI API + `pd todo`).
 * Types: client, project, task (a task with parent_id is a subtask).
 * Actor: "user" (UI) or "ai" (agents via the CLI).
 */
class TodoService
{
    public const TYPES = ['client' => TodoClient::class, 'project' => TodoProject::class, 'task' => TodoTask::class];

    /** Timezone for "today", "tomorrow" and overdue (the app itself runs in UTC). */
    public static function timezone(): string
    {
        return config('app.user_timezone', 'Europe/Amsterdam');
    }

    public static function today(): Carbon
    {
        return Carbon::now(self::timezone())->startOfDay();
    }

    /** Find by primary key without letting a non-uuid string reach Postgres (uuid column). */
    public static function findKey(string $class, mixed $id): ?Model
    {
        return is_string($id) && Str::isUuid($id) ? $class::find(strtolower($id)) : null;
    }

    /** Full state for the UI. */
    public function snapshot(): array
    {
        $projects = TodoProject::orderBy('sort_order')->orderBy('created_at')->get();
        $tasks = TodoTask::orderBy('sort_order')->orderBy('created_at')->get();

        $sessionIds = $projects->pluck('session_id')->merge($tasks->pluck('session_id'))->filter()->unique()->values();

        return [
            'clients' => TodoClient::orderBy('sort_order')->orderBy('name')->get()->map->toTodoArray()->values(),
            'projects' => $projects->map->toTodoArray()->values(),
            'tasks' => $tasks->map->toTodoArray()->values(),
            'apps' => AppTrayItem::ordered()->get()->map(fn($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'url' => $a->url,
                'icon' => AppTrayItem::normalizeIcon($a->icon),
                'icon_type' => $a->icon_type,
            ])->values(),
            'sessions' => Session::whereIn('id', $sessionIds)->get(['id', 'name', 'is_archived'])
                ->mapWithKeys(fn($s) => [$s->id => ['name' => $s->name ?: 'Sessie', 'archived' => (bool) $s->is_archived]]),
        ];
    }

    public function modelClass(string $type): string
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Type must be client, project or task');
        }

        return self::TYPES[$type];
    }

    /**
     * Short id shown in `pd todo list`: the last 8 hex chars (UUIDv7 starts with a timestamp,
     * so prefixes of items created together are identical).
     */
    public static function shortId(string $id): string
    {
        return substr($id, -8);
    }

    /**
     * Find by full id, short id (>= 4 trailing chars, as shown by `pd todo list`) or exact name/title (case-insensitive).
     */
    public function resolve(string $type, ?string $ref): ?Model
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        $class = $this->modelClass($type);

        if ($m = self::findKey($class, $ref)) {
            return $m;
        }
        if (preg_match('/^[0-9a-f]{4,}$/i', $ref)) {
            $matches = $class::whereRaw('CAST(id AS TEXT) LIKE ?', ['%' . strtolower($ref)])->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
            if ($matches->count() > 1) {
                throw new InvalidArgumentException("Short id \"{$ref}\" is ambiguous; use the full id");
            }
        }

        $column = $type === 'task' ? 'title' : 'name';
        $matches = $class::whereRaw("LOWER({$column}) = ?", [mb_strtolower($ref)])->limit(2)->get();
        if ($matches->count() > 1) {
            throw new InvalidArgumentException("More than one {$type} is called \"{$ref}\"; use the id");
        }

        return $matches->first();
    }

    public function create(string $type, array $data, string $actor): Model
    {
        $class = $this->modelClass($type);
        $attrs = $this->validate($type, $data, null);

        if ($type !== 'client') {
            $attrs['created_by'] = $actor;
        }
        $attrs['sort_order'] ??= ((int) $class::max('sort_order')) + 10;

        return $class::create($attrs);
    }

    public function update(string $type, Model $model, array $data): Model
    {
        $model->fill($this->validate($type, $data, $model));
        $model->save();

        // A subtask follows its parent when the parent moves to another project
        if ($type === 'task' && $model->wasChanged('project_id')) {
            TodoTask::where('parent_id', $model->id)->update(['project_id' => $model->project_id]);
        }

        return $model;
    }

    /**
     * Check off (or reopen).
     * - Checking off a task/project also checks off its open subtasks/tasks, with the same timestamp,
     *   so reopening it restores exactly those (items checked off earlier stay done).
     * - Reopening a subtask/task inside a done task/project reopens that parent, so it never ends up hidden.
     */
    public function setDone(string $type, Model $model, bool $done, string $actor): Model
    {
        if ($type === 'client') {
            throw new InvalidArgumentException('Clients cannot be checked off');
        }
        $children = $type === 'task'
            ? TodoTask::where('parent_id', $model->id)
            : TodoTask::where('project_id', $model->id);

        if ($done) {
            if ($model->done_at) {
                return $model;
            }
            $now = now()->startOfSecond();
            $model->done_at = $now;
            $model->done_by = $actor;
            $model->save();
            (clone $children)->whereNull('done_at')->update(['done_at' => $now, 'done_by' => $actor]);

            return $model;
        }

        if ($previous = $model->done_at) {
            (clone $children)->where('done_at', $previous)->update(['done_at' => null, 'done_by' => null]);
        }
        $model->done_at = null;
        $model->done_by = null;
        $model->save();

        if ($type === 'task') {
            if ($model->parent_id) {
                TodoTask::whereKey($model->parent_id)->whereNotNull('done_at')->update(['done_at' => null, 'done_by' => null]);
            }
            if ($model->project_id) {
                TodoProject::whereKey($model->project_id)->whereNotNull('done_at')->update(['done_at' => null, 'done_by' => null]);
            }
        }

        return $model;
    }

    /**
     * Validate and normalise the given fields (only keys present in $data are touched).
     * Accepted keys: name/title, notes, client_id, project_id, parent_id, deadline, session_id, app_tray_item_id, sort_order.
     */
    private function validate(string $type, array $data, ?Model $existing): array
    {
        foreach ($data as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException("Invalid value for {$key}");
            }
        }
        $out = [];
        $labelKey = $type === 'task' ? 'title' : 'name';
        $max = ['client' => 100, 'project' => 150, 'task' => 255][$type];

        $label = $data[$labelKey] ?? $data['name'] ?? $data['title'] ?? null;
        if ($label !== null || !$existing) {
            $label = trim((string) $label);
            if ($label === '') {
                throw new InvalidArgumentException(ucfirst($labelKey) . ' is required');
            }
            if (mb_strlen($label) > $max) {
                throw new InvalidArgumentException(ucfirst($labelKey) . " is too long (max {$max})");
            }
            $out[$labelKey] = $label;
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string) $data['notes']);
            $out['notes'] = $notes === '' ? null : mb_substr($notes, 0, 10000);
        }
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $out['sort_order'] = max(-1000000000, min(1000000000, (int) $data['sort_order']));
        }

        if ($type === 'client') {
            return $out;
        }

        if (array_key_exists('deadline', $data)) {
            $out['deadline'] = $this->parseDeadline($data['deadline']);
        }
        if (array_key_exists('session_id', $data)) {
            $id = $data['session_id'] ?: null;
            if ($id && !self::findKey(Session::class, $id)) {
                throw new InvalidArgumentException('Session not found');
            }
            $out['session_id'] = $id;
        }
        if (array_key_exists('app_tray_item_id', $data)) {
            $id = $data['app_tray_item_id'] ?: null;
            if ($id && !self::findKey(AppTrayItem::class, $id)) {
                throw new InvalidArgumentException('App not found in the App Tray');
            }
            $out['app_tray_item_id'] = $id;
        }

        if ($type === 'project') {
            if (array_key_exists('client_id', $data)) {
                $id = $data['client_id'] ?: null;
                if ($id && !self::findKey(TodoClient::class, $id)) {
                    throw new InvalidArgumentException('Client not found');
                }
                $out['client_id'] = $id;
            }

            return $out;
        }

        // Task / subtask
        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] ?: null;
            if ($parentId) {
                $parent = self::findKey(TodoTask::class, $parentId);
                if (!$parent) {
                    throw new InvalidArgumentException('Parent task not found');
                }
                if ($parent->parent_id) {
                    throw new InvalidArgumentException('Subtasks cannot have subtasks (one level deep)');
                }
                if ($existing && ($parent->id === $existing->id || TodoTask::where('parent_id', $existing->id)->exists())) {
                    throw new InvalidArgumentException('This task has subtasks itself and cannot become a subtask');
                }
                $out['project_id'] = $parent->project_id; // subtasks live in their parent's project
            }
            $out['parent_id'] = $parentId;
        }
        $isSubtask = array_key_exists('parent_id', $out) ? (bool) $out['parent_id'] : (bool) $existing?->parent_id;
        if (array_key_exists('project_id', $data) && $isSubtask && !array_key_exists('parent_id', $data)
            && ($data['project_id'] ?: null) !== $existing?->project_id) {
            throw new InvalidArgumentException('A subtask follows its parent: move the parent task, or make it a normal task first (parent = none)');
        }
        if (array_key_exists('project_id', $data) && !$isSubtask) {
            $id = $data['project_id'] ?: null;
            if ($id && !self::findKey(TodoProject::class, $id)) {
                throw new InvalidArgumentException('Project not found');
            }
            $out['project_id'] = $id;
        }

        return $out;
    }

    private function parseDeadline(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || in_array(strtolower($value), ['none', 'null', 'geen'], true)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException("Deadline \"{$value}\" is not a valid date");
            }

            return $value;
        }
        $aliases = ['vandaag' => 'today', 'morgen' => 'tomorrow', 'overmorgen' => '+2 days'];
        $value = $aliases[strtolower($value)] ?? $value;
        try {
            // Relative dates ("tomorrow", "next friday") count from the user's local day
            return Carbon::parse($value, self::timezone())->format('Y-m-d');
        } catch (\Throwable) {
            throw new InvalidArgumentException("Deadline \"{$value}\" not understood; use YYYY-MM-DD, \"tomorrow\", \"next friday\" or \"+3 days\"");
        }
    }
}
