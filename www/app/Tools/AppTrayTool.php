<?php

namespace App\Tools;

/**
 * App Tray: link tiles the user opens from the grid button in PocketDev.
 *
 * Documentation-only for CLI providers; execution is via `pd app-tray`.
 */
class AppTrayTool extends Tool
{
    public string $name = 'AppTray';

    public string $description = 'Add, update or remove link tiles (icon + title + subtitle, optional sub-links to specific pages) in the PocketDev App Tray.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'list, add, update, remove, link (add/update a sub-link on an app) or unlink',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Short tile title (max 60). Required for add. For update/remove without --id: the exact current title. For link/unlink: the sub-link title.',
            ],
            'app' => [
                'type' => 'string',
                'description' => 'link/unlink: exact title of the app the sub-link belongs to.',
            ],
            'subtitle' => [
                'type' => 'string',
                'description' => 'One short line under the title (max 120), e.g. what it is or where it runs.',
            ],
            'url' => [
                'type' => 'string',
                'description' => 'https://... link, or a PocketDev path like /config. Required for add.',
            ],
            'icon' => [
                'type' => 'string',
                'description' => 'Font Awesome 6 class ("fa-solid fa-camera", "fa-brands fa-github"), an emoji, or an image URL (https://.../icon.png). Default: link icon.',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Sort position, lower comes first. Default: at the end.',
            ],
            'new_title' => [
                'type' => 'string',
                'description' => 'New title (update only).',
            ],
            'id' => [
                'type' => 'string',
                'description' => 'Item UUID (from list) for update/remove.',
            ],
        ],
        'required' => ['action'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'app-tray';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
The App Tray is the user's launcher: the grid button (always visible in the PocketDev header/sidebar) opens a panel of tiles. Each tile is just an icon, a title, a small subtitle and a link.

**When to use:**
- You deployed or built something the user will open again (a web app, dashboard, staging site, admin page): add a tile.
- The user asks to "put it in the app tray / launcher".
- An app moved or was removed: update or remove its tile. Run `list` first to avoid duplicates.

**Guidelines:**
- Title: the app name, short (fits under an icon). Subtitle: one short line (what it is / environment), not a description.
- Icon: pick a fitting Font Awesome 6 icon (fa-solid / fa-brands), or the app's own icon URL, or an emoji.
- Links open in a new tab; PocketDev paths (starting with /) open in place.

**Depth (sub-links):** an app can have up to 8 sub-links to specific pages (e.g. an admin page, a staging environment, a dashboard tab). Tapping such an app shows "Open" plus its sub-links instead of opening it directly. Add 2-3 when the user will often need a specific page; `link` with an existing sub-link title updates it.
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Examples

```bash
pd app-tray list
pd app-tray add --title="Studio" --subtitle="fal.ai beeld en video" --url="https://studio.bartluttels.nl" --icon="fa-solid fa-wand-magic-sparkles"
pd app-tray add --title="Travel Duo" --subtitle="Reisplanner" --url="https://travel.bartluttels.nl" --icon="✈️"
pd app-tray update --title="Studio" --subtitle="Productie" --position=1
pd app-tray remove --title="Travel Duo"

# Sub-links (depth): tapping "Studio" then shows Open + these pages
pd app-tray link --app="Studio" --title="Bibliotheek" --url="https://studio.bartluttels.nl/#/library" --icon="fa-solid fa-images"
pd app-tray link --app="Studio" --title="Te beoordelen" --url="https://studio.bartluttels.nl/#/social?f=to_review" --icon="fa-solid fa-check-double"
pd app-tray unlink --app="Studio" --title="Bibliotheek"
```
CLI;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        return ToolResult::error('This tool should be invoked via: pd app-tray <action>');
    }
}
