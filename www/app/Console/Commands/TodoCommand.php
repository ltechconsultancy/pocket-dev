<?php

namespace App\Console\Commands;

use App\Models\AppTrayItem;
use App\Models\Session;
use App\Models\TodoClient;
use App\Models\TodoProject;
use App\Models\TodoTask;
use App\Services\TodoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Todo app for agents: clients > projects > tasks > subtasks, with deadlines
 * and links to sessions and App Tray apps. The user sees the same list in the UI.
 */
class TodoCommand extends Command
{
    protected $signature = 'todo
        {action : list, add, update, done, undo or remove}
        {--type= : client, project or task (default: task for add; auto-detected for --id)}
        {--id= : Item id, short id (from list) or exact name/title}
        {--title= : Task title (add/update)}
        {--name= : Client or project name (add/update)}
        {--client= : Client name or id (project add/update; list filter)}
        {--project= : Project name or id (task add/update; list filter). "none" = Inbox}
        {--parent= : Parent task id or title: makes this a subtask}
        {--deadline= : YYYY-MM-DD, "tomorrow", "next friday", "+3 days"; "none" clears}
        {--notes= : Free text notes}
        {--session= : "current" (default on add), "none", or a session id}
        {--app= : App Tray app title or id; "none" clears}
        {--position= : Sort position (lower comes first)}
        {--all : list: include checked-off items}';

    protected $description = 'Manage the todo list (clients, projects, tasks, subtasks)';

    public function __construct(private TodoService $todos)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->listTodos(),
                'add' => $this->add(),
                'update' => $this->updateItem(),
                'done' => $this->setDone(true),
                'undo' => $this->setDone(false),
                'remove' => $this->remove(),
                default => $this->failWith('Unknown action. Use: list, add, update, done, undo, remove'),
            };
        } catch (InvalidArgumentException $e) {
            return $this->failWith($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->failWith('Database error: ' . strtok($e->getMessage(), "\n"));
        }
    }

    private function add(): int
    {
        $type = $this->option('type') ?: 'task';
        $this->todos->modelClass($type);

        $data = $this->collectFields($type, true);
        $item = $this->todos->create($type, $data, 'ai');

        return $this->ok('Added ' . $this->describe($type, $item) . '.', $type, $item);
    }

    private function updateItem(): int
    {
        [$type, $item] = $this->target();
        $data = $this->collectFields($type, false);
        // --title/--name select the item when no --id is given; only rename when --id is used
        if (!$this->option('id')) {
            unset($data['title'], $data['name']);
        }
        if (!$data) {
            return $this->failWith('Nothing to update: pass e.g. --deadline, --notes, --project, --app, --session or --title/--name');
        }
        $item = $this->todos->update($type, $item, $data);

        return $this->ok('Updated ' . $this->describe($type, $item) . '.', $type, $item);
    }

    private function setDone(bool $done): int
    {
        [$type, $item] = $this->target();
        $item = $this->todos->setDone($type, $item, $done, 'ai');

        return $this->ok(($done ? 'Checked off ' : 'Reopened ') . $this->describe($type, $item) . '.', $type, $item);
    }

    private function remove(): int
    {
        [$type, $item] = $this->target();
        $label = $this->describe($type, $item);
        $extra = match ($type) {
            'project' => ' and its ' . $item->tasks()->count() . ' task(s)',
            'task' => ($n = $item->subtasks()->count()) ? " and {$n} subtask(s)" : '',
            'client' => ' (its projects are kept, without client)',
        };
        $item->delete();

        return $this->ok("Removed {$label}{$extra}.");
    }

    /** @return array{0: string, 1: Model} */
    private function target(): array
    {
        $ref = $this->option('id') ?: $this->option('title') ?: $this->option('name');
        if (!$ref) {
            throw new InvalidArgumentException('Pass --id (id, short id or exact name/title)');
        }
        $types = $this->option('type') ? [$this->option('type')] : ['task', 'project', 'client'];
        foreach ($types as $type) {
            if ($item = $this->todos->resolve($type, $ref)) {
                return [$type, $item];
            }
        }

        throw new InvalidArgumentException("Nothing found for \"{$ref}\" (run `pd todo list --all` for ids)");
    }

    private function collectFields(string $type, bool $isAdd): array
    {
        $data = [];
        $label = $type === 'task' ? ($this->option('title') ?? $this->option('name')) : ($this->option('name') ?? $this->option('title'));
        if ($label !== null) {
            $data[$type === 'task' ? 'title' : 'name'] = $label;
        }
        foreach (['notes', 'deadline'] as $key) {
            if ($this->option($key) !== null) {
                $data[$key] = $this->option($key);
            }
        }
        if ($this->option('position') !== null) {
            $data['sort_order'] = (int) $this->option('position');
        }
        if ($type === 'client') {
            return $data;
        }

        // Session link: agents link new items to the session they work in
        $session = $this->option('session') ?? ($isAdd ? 'current' : null);
        if ($session !== null) {
            $data['session_id'] = match (strtolower($session)) {
                'current' => getenv('POCKETDEV_SESSION_ID') ?: null,
                'none', '' => null,
                default => TodoService::findKey(Session::class, $session)?->id ?? throw new InvalidArgumentException('Session not found'),
            };
        }
        if (($app = $this->option('app')) !== null) {
            $data['app_tray_item_id'] = $this->resolveApp($app);
        }

        if ($type === 'project' && ($client = $this->option('client')) !== null) {
            $data['client_id'] = $this->resolveRef('client', $client);
        }
        if ($type === 'task') {
            if (($project = $this->option('project')) !== null) {
                $data['project_id'] = $this->resolveRef('project', $project);
            }
            if (($parent = $this->option('parent')) !== null) {
                $data['parent_id'] = $this->resolveRef('task', $parent);
            }
        }

        return $data;
    }

    private function resolveRef(string $type, string $ref): ?string
    {
        if (in_array(strtolower(trim($ref)), ['none', 'inbox', ''], true)) {
            return null;
        }
        $item = $this->todos->resolve($type, $ref);
        if (!$item) {
            $hint = $type === 'task' ? '' : " Add it first: pd todo add --type={$type} --name=\"{$ref}\"";
            throw new InvalidArgumentException(ucfirst($type) . " \"{$ref}\" not found.{$hint}");
        }

        return $item->id;
    }

    private function resolveApp(string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || strtolower($ref) === 'none') {
            return null;
        }
        $app = TodoService::findKey(AppTrayItem::class, $ref) ?? AppTrayItem::whereRaw('LOWER(title) = ?', [mb_strtolower($ref)])->first();
        if (!$app) {
            throw new InvalidArgumentException("App \"{$ref}\" is not in the App Tray (see `pd app-tray list`)");
        }

        return $app->id;
    }

    // ===== List =====

    private function listTodos(): int
    {
        $all = (bool) $this->option('all');
        $clientFilter = $this->option('client') ? $this->todos->resolve('client', $this->option('client')) : null;
        $projectFilter = $this->option('project') && !in_array(strtolower($this->option('project')), ['none', 'inbox'], true)
            ? $this->todos->resolve('project', $this->option('project')) : null;
        if ($this->option('client') && !$clientFilter) {
            return $this->failWith('Client not found');
        }
        if ($projectFilter === null && $this->option('project') && !in_array(strtolower($this->option('project')), ['none', 'inbox'], true)) {
            return $this->failWith('Project not found');
        }
        $inboxOnly = $this->option('project') && in_array(strtolower($this->option('project')), ['none', 'inbox'], true);

        $tasks = TodoTask::orderBy('sort_order')->orderBy('created_at')->get();
        if (!$all) {
            $tasks = $tasks->whereNull('done_at');
        }
        $byProject = $tasks->whereNull('parent_id')->groupBy(fn($t) => $t->project_id ?? '');
        $subs = $tasks->whereNotNull('parent_id')->groupBy('parent_id');
        $apps = AppTrayItem::pluck('title', 'id');
        $sessions = Session::whereIn('id', $tasks->pluck('session_id')->filter()->unique())->pluck('name', 'id');

        $projects = TodoProject::orderBy('sort_order')->orderBy('created_at')->get()
            ->when(!$all, fn($c) => $c->whereNull('done_at'));
        $clients = TodoClient::orderBy('sort_order')->orderBy('name')->get();

        $lines = [];
        $open = $tasks->whereNull('done_at')->count();

        $renderTasks = function ($list, string $indent) use (&$lines, $subs, $apps, $sessions) {
            foreach ($list as $t) {
                $lines[] = $indent . $this->taskLine($t, $apps, $sessions);
                foreach ($subs->get($t->id, []) as $s) {
                    $lines[] = $indent . '    ' . $this->taskLine($s, $apps, $sessions);
                }
            }
        };
        $renderProject = function (TodoProject $p, string $indent) use (&$lines, $byProject, $renderTasks, $apps) {
            $ptasks = $byProject->get($p->id, collect());
            $meta = array_filter([
                $p->deadline ? $this->deadlineLabel($p->deadline) : null,
                $p->done_at ? 'DONE' : null,
                $p->app_tray_item_id ? 'app "' . ($apps[$p->app_tray_item_id] ?? '?') . '"' : null,
            ]);
            $lines[] = $indent . 'Project: ' . $p->name . '  [' . TodoService::shortId($p->id) . ']' . ($meta ? '  (' . implode(', ', $meta) . ')' : '');
            if ($ptasks->isEmpty()) {
                $lines[] = $indent . '  (no open tasks)';
            }
            $renderTasks($ptasks, $indent . '  ');
        };

        if (!$clientFilter && !$projectFilter) {
            $inbox = $byProject->get('', collect());
            if ($inbox->isNotEmpty() || $inboxOnly) {
                $lines[] = 'Inbox (no project)';
                $renderTasks($inbox, '  ');
                $lines[] = '';
            }
        }

        if (!$inboxOnly) {
            foreach ($clients as $c) {
                if ($clientFilter && $clientFilter->id !== $c->id) {
                    continue;
                }
                $cprojects = $projects->where('client_id', $c->id)
                    ->when($projectFilter, fn($col) => $col->where('id', $projectFilter->id));
                if ($projectFilter && $cprojects->isEmpty()) {
                    continue;
                }
                $lines[] = 'Client: ' . $c->name . '  [' . TodoService::shortId($c->id) . ']';
                if ($cprojects->isEmpty()) {
                    $lines[] = '  (no projects)';
                }
                foreach ($cprojects as $p) {
                    $renderProject($p, '  ');
                }
                $lines[] = '';
            }
            if (!$clientFilter) {
                $loose = $projects->whereNull('client_id')
                    ->when($projectFilter, fn($col) => $col->where('id', $projectFilter->id));
                if ($loose->isNotEmpty()) {
                    $lines[] = 'No client';
                    foreach ($loose as $p) {
                        $renderProject($p, '  ');
                    }
                }
            }
        }

        $header = "{$open} open task(s). Pass the [short id] to --id.";
        $body = trim(implode("\n", $lines));

        return $this->ok($header . "\n\n" . ($body !== '' ? $body : 'The todo list is empty.'));
    }

    private function taskLine(TodoTask $t, $apps, $sessions): string
    {
        $meta = array_filter([
            $t->deadline ? $this->deadlineLabel($t->deadline) : null,
            $t->created_by === 'ai' ? 'by AI' : null,
            $t->done_at ? 'done by ' . ($t->done_by === 'ai' ? 'AI' : 'user') : null,
            $t->session_id ? 'session "' . ($sessions[$t->session_id] ?? 'Sessie') . '"' : null,
            $t->app_tray_item_id ? 'app "' . ($apps[$t->app_tray_item_id] ?? '?') . '"' : null,
        ]);

        return ($t->done_at ? '[x] ' : '[ ] ') . '[' . TodoService::shortId($t->id) . '] ' . $t->title
            . ($meta ? '  (' . implode(', ', $meta) . ')' : '')
            . ($t->notes ? "\n" . str_repeat(' ', 16) . 'notes: ' . str_replace("\n", ' / ', mb_strimwidth($t->notes, 0, 200, '...')) : '');
    }

    private function deadlineLabel($date): string
    {
        $days = (int) TodoService::today()->diffInDays(Carbon::createFromFormat('!Y-m-d', $date->format('Y-m-d'), TodoService::timezone()), false);
        $suffix = match (true) {
            $days < 0 => ', OVERDUE',
            $days === 0 => ', today',
            $days === 1 => ', tomorrow',
            default => '',
        };

        return 'deadline ' . $date->format('Y-m-d') . $suffix;
    }

    // ===== Output =====

    private function describe(string $type, Model $item): string
    {
        $label = $type === 'task' ? $item->title : $item->name;
        $kind = $type === 'task' && $item->parent_id ? 'subtask' : $type;

        return "{$kind} \"{$label}\" [" . TodoService::shortId($item->id) . ']';
    }

    private function ok(string $message, ?string $type = null, ?Model $item = null): int
    {
        $out = ['output' => $message, 'is_error' => false];
        if ($item) {
            $out['type'] = $type;
            $out['item'] = $item->toTodoArray();
        }
        $this->outputJson($out);

        return Command::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->outputJson(['output' => $message, 'is_error' => true]);

        return Command::FAILURE;
    }

    private function outputJson(array $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
