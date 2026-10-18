<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppTrayItem;
use Illuminate\Http\JsonResponse;

/**
 * Read-only list for the App Tray panel (items are managed via `pd app-tray`).
 * PocketDev's own Settings is always the first, built-in tile.
 */
class AppTrayController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'items' => collect([self::settingsItem()])
                ->concat(AppTrayItem::ordered()->get()->map->toTrayArray())
                ->values(),
        ]);
    }

    /**
     * Built-in tile (not stored, cannot be removed by agents).
     */
    private static function settingsItem(): array
    {
        $link = fn(string $title, string $route, string $icon) => [
            'title' => $title,
            'url' => route($route, [], false),
            'icon' => $icon,
            'icon_type' => 'fa',
        ];

        return [
            'id' => 'builtin-settings',
            'builtin' => true,
            'title' => 'Instellingen',
            'subtitle' => 'PocketDev',
            'url' => route('config.index', [], false),
            'icon' => 'fa-solid fa-gear',
            'icon_type' => 'fa',
            'links' => [
                $link('Agents', 'config.agents', 'fa-solid fa-robot'),
                $link('Credentials', 'config.credentials', 'fa-solid fa-key'),
                $link('Workspaces', 'config.workspaces', 'fa-solid fa-folder'),
                $link('Tools', 'config.tools', 'fa-solid fa-screwdriver-wrench'),
                $link('Geheugen', 'config.memory', 'fa-solid fa-database'),
                $link('Meldingen', 'config.notifications', 'fa-solid fa-bell'),
                $link('Systeem', 'config.system', 'fa-solid fa-server'),
                $link('Gebruik', 'config.usage', 'fa-solid fa-chart-line'),
            ],
            'sort_order' => -1,
        ];
    }
}
