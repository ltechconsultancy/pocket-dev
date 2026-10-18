<?php

namespace App\Console\Commands;

use App\Models\AppTrayItem;
use Illuminate\Console\Command;

/**
 * Manage the App Tray: link tiles (icon + title + subtitle) shown from the
 * grid button in the PocketDev UI.
 */
class AppTrayCommand extends Command
{
    protected $signature = 'app-tray
        {action : list, add, update, remove, link or unlink}
        {--title= : Tile title (required for add; selects the item for update/remove when --id is omitted). For link/unlink: the sub-link title}
        {--app= : link/unlink: title of the app the sub-link belongs to (or use --id)}
        {--subtitle= : Small line under the title}
        {--url= : Link target: https://... or a PocketDev path like /config}
        {--icon= : Font Awesome class (fa-solid fa-camera), an emoji, or an image URL}
        {--position= : Sort position (lower comes first)}
        {--new_title= : New title (update only)}
        {--id= : Item UUID (update/remove)}';

    protected $description = 'Manage the App Tray (link tiles with icon, title and subtitle)';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listItems(),
            'add' => $this->addItem(),
            'update' => $this->updateItem(),
            'remove' => $this->removeItem(),
            'link' => $this->linkItem(),
            'unlink' => $this->unlinkItem(),
            default => $this->failWith('Unknown action. Use: list, add, update, remove, link, unlink'),
        };
    }

    private function listItems(): int
    {
        $items = AppTrayItem::ordered()->get()->map->toTrayArray()->values();

        $this->outputJson([
            'output' => $items->isEmpty() ? 'The App Tray is empty.' : "{$items->count()} item(s) in the App Tray.",
            'items' => $items,
            'count' => $items->count(),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function addItem(): int
    {
        $title = trim((string) $this->option('title'));
        $url = trim((string) $this->option('url'));

        if ($title === '' || $url === '') {
            return $this->failWith('--title and --url are required for add');
        }
        if ($error = $this->validateFields($title, $url, $this->option('icon'), $this->option('subtitle'))) {
            return $this->failWith($error);
        }

        $item = AppTrayItem::create([
            'title' => $title,
            'subtitle' => $this->nullIfEmpty($this->option('subtitle')),
            'url' => $url,
            'icon' => $this->nullIfEmpty($this->option('icon')),
            'sort_order' => $this->option('position') !== null
                ? (int) $this->option('position')
                : ((int) AppTrayItem::max('sort_order')) + 10,
        ]);

        $this->outputJson([
            'output' => "Added \"{$item->title}\" to the App Tray.",
            'item' => $item->toTrayArray(),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function updateItem(): int
    {
        $item = $this->findItem();
        if (!$item) {
            return $this->failWith('Item not found (use --id, or --title with the exact current title)');
        }

        $title = $this->option('new_title') !== null ? trim((string) $this->option('new_title')) : $item->title;
        $url = $this->option('url') !== null ? trim((string) $this->option('url')) : $item->url;
        $icon = $this->option('icon') !== null ? $this->option('icon') : $item->icon;
        $subtitle = $this->option('subtitle') !== null ? $this->option('subtitle') : $item->subtitle;

        if ($error = $this->validateFields($title, $url, $icon, $subtitle)) {
            return $this->failWith($error);
        }

        $item->fill([
            'title' => $title,
            'url' => $url,
            'icon' => $this->nullIfEmpty($icon),
            'subtitle' => $this->nullIfEmpty($subtitle),
        ]);
        if ($this->option('position') !== null) {
            $item->sort_order = (int) $this->option('position');
        }
        $item->save();

        $this->outputJson([
            'output' => "Updated \"{$item->title}\".",
            'item' => $item->toTrayArray(),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function removeItem(): int
    {
        $item = $this->findItem();
        if (!$item) {
            return $this->failWith('Item not found (use --id, or --title with the exact current title)');
        }

        $title = $item->title;
        $item->delete();

        $this->outputJson([
            'output' => "Removed \"{$title}\" from the App Tray.",
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Add (or update, matched on title) a sub-link: a specific page of the app.
     */
    private function linkItem(): int
    {
        $item = $this->findApp();
        if (!$item) {
            return $this->failWith('App not found (use --app with the exact app title, or --id)');
        }

        $title = trim((string) $this->option('title'));
        $url = trim((string) $this->option('url'));
        if ($title === '' || $url === '') {
            return $this->failWith('--title and --url are required for link');
        }
        if ($error = $this->validateFields($title, $url, $this->option('icon'), null)) {
            return $this->failWith($error);
        }

        $links = array_values($item->links ?? []);
        $link = ['title' => $title, 'url' => $url, 'icon' => $this->nullIfEmpty($this->option('icon'))];
        $existing = array_search(mb_strtolower($title), array_map(fn($l) => mb_strtolower($l['title'] ?? ''), $links), true);

        if ($existing !== false) {
            // Only change what was passed: keep the current icon when --icon is omitted
            if ($this->option('icon') === null) {
                $link['icon'] = $links[$existing]['icon'] ?? null;
            }
            $links[$existing] = $link;
            $verb = 'Updated';
        } else {
            if (count($links) >= AppTrayItem::MAX_LINKS) {
                return $this->failWith('An app can have at most ' . AppTrayItem::MAX_LINKS . ' sub-links; unlink one first');
            }
            $links[] = $link;
            $verb = 'Added';
        }

        $item->links = $links;
        $item->save();

        $this->outputJson([
            'output' => "{$verb} sub-link \"{$title}\" on \"{$item->title}\".",
            'item' => $item->toTrayArray(),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function unlinkItem(): int
    {
        $item = $this->findApp();
        if (!$item) {
            return $this->failWith('App not found (use --app with the exact app title, or --id)');
        }

        $title = mb_strtolower(trim((string) $this->option('title')));
        $links = array_values($item->links ?? []);
        $kept = array_values(array_filter($links, fn($l) => mb_strtolower($l['title'] ?? '') !== $title));
        if (count($kept) === count($links)) {
            return $this->failWith('Sub-link not found on "' . $item->title . '"');
        }

        $item->links = $kept ?: null;
        $item->save();

        $this->outputJson([
            'output' => "Removed sub-link from \"{$item->title}\".",
            'item' => $item->toTrayArray(),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function findApp(): ?AppTrayItem
    {
        if ($id = $this->option('id')) {
            return AppTrayItem::find($id);
        }
        if ($app = $this->option('app')) {
            return AppTrayItem::where('title', trim($app))->first();
        }

        return null;
    }

    private function findItem(): ?AppTrayItem
    {
        if ($id = $this->option('id')) {
            return AppTrayItem::find($id);
        }
        if ($title = $this->option('title')) {
            return AppTrayItem::where('title', trim($title))->first();
        }

        return null;
    }

    private function validateFields(string $title, string $url, ?string $icon, ?string $subtitle): ?string
    {
        if (mb_strlen($title) > 60) {
            return 'Title is too long (max 60 characters)';
        }
        if ($subtitle !== null && mb_strlen($subtitle) > 120) {
            return 'Subtitle is too long (max 120 characters)';
        }
        // Only web links or PocketDev paths (no javascript:, data:, file: ...)
        if (!preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', $url)) {
            return 'URL must start with https://, http:// or / (a PocketDev path)';
        }
        $icon = trim((string) $icon);
        if ($icon !== '' && preg_match('#^[a-z]+:#i', $icon) && !preg_match('#^https?://#i', $icon)) {
            return 'Icon image URL must start with https:// or http://';
        }
        if (mb_strlen($icon) > 500) {
            return 'Icon is too long';
        }

        return null;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
