<?php

namespace App\Tools;

/**
 * Todo app: clients > projects > tasks > subtasks, shared between user and agents.
 *
 * Documentation-only for CLI providers; execution is via `pd todo`.
 */
class TodoTool extends Tool
{
    public string $name = 'Todo';

    public string $description = 'Shared todo list of the user and agents: clients, projects, tasks and subtasks with deadlines, linked to sessions and App Tray apps. Add, update and check off items.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'description' => 'list, add, update, done (check off), undo (reopen) or remove'],
            'type' => ['type' => 'string', 'description' => 'client, project or task (default: task for add; auto-detected from --id otherwise)'],
            'id' => ['type' => 'string', 'description' => 'Item to update/done/undo/remove: id, the 8-char short id from list, or the exact name/title'],
            'title' => ['type' => 'string', 'description' => 'Task title (max 255). Short and actionable.'],
            'name' => ['type' => 'string', 'description' => 'Client name (max 100) or project name (max 150)'],
            'client' => ['type' => 'string', 'description' => 'Client name or id: the client of a project (add/update), or a list filter'],
            'project' => ['type' => 'string', 'description' => 'Project name or id of a task (add/update), or a list filter. "none" = Inbox'],
            'parent' => ['type' => 'string', 'description' => 'Parent task id or title: makes the task a subtask (one level deep, inherits the project)'],
            'deadline' => ['type' => 'string', 'description' => 'YYYY-MM-DD, "tomorrow", "next friday", "+3 days"; "none" clears'],
            'notes' => ['type' => 'string', 'description' => 'Free text notes / context'],
            'session' => ['type' => 'string', 'description' => '"current" (default on add: the session you are working in), "none", or a session id'],
            'app' => ['type' => 'string', 'description' => 'App Tray app title or id to link (see pd app-tray list); "none" clears'],
            'position' => ['type' => 'integer', 'description' => 'Sort position, lower comes first'],
            'all' => ['type' => 'boolean', 'description' => 'list: include checked-off tasks and projects'],
        ],
        'required' => ['action'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'todo';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
A simple todo list the user and you share. The user sees it via the checklist button in the PocketDev header and can add and check off items there too.

Structure: **clients > projects > tasks > subtasks** (one level of subtasks). Tasks without a project live in the Inbox; projects without a client are fine. Tasks and projects can have a deadline and can be linked to a PocketDev session (default: the session you add them from) and to an App Tray app.

**When to use:**
- The user asks to note, plan or remember work ("zet op mijn todo", "maak taken voor ...", "wat staat er nog open?").
- You finish something that is on the list: check it off with `done`. Run `list` first to find the id.
- You discover follow-up work the user should not forget: add a task (and tell the user you did).

**Guidelines:**
- Run `pd todo list` before adding, to reuse existing clients/projects and avoid duplicates.
- Titles are short and actionable ("Factuur sturen naar Hotraco"), details go in --notes.
- Items you add or check off are marked as done/added by AI in the UI. Only check off what is really finished.
- Use `--all` to also see checked-off items; ids in the list are 8-char short ids you can pass to --id.
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Examples

```bash
pd todo list
pd todo list --client="Hotraco" --all

pd todo add --type=client --name="Hotraco"
pd todo add --type=project --name="COTI Translate" --client="Hotraco" --deadline=2026-12-01 --app="COTI"
pd todo add --title="TMX-import testen" --project="COTI Translate" --deadline="next friday"
pd todo add --title="Grote bestanden" --parent=1a2b3c4d          # subtask
pd todo add --title="Tandarts bellen" --deadline=tomorrow --session=none   # Inbox, not linked to this session

pd todo done --id=1a2b3c4d
pd todo undo --id=1a2b3c4d
pd todo update --id=1a2b3c4d --deadline=none --notes="Wacht op klant"
pd todo update --id=1a2b3c4d --title="Nieuwe titel" --project=none   # rename, move to Inbox
pd todo remove --id=1a2b3c4d
```
CLI;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        return ToolResult::error('This tool should be invoked via: pd todo <action>');
    }
}
