<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppTrayItem;
use App\Models\TodoTask;
use App\Services\TodoService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only list for the App Tray panel (items are managed via `pd app-tray`).
 * PocketDev's own Settings lives in the ⋮ menu, not in the tray.
 */
class AppTrayController extends Controller
{
    public function index(): JsonResponse
    {
        $openTasks = TodoTask::whereNull('done_at');

        return response()->json([
            'items' => AppTrayItem::ordered()->get()->map->toTrayArray()->values(),
            // Badge on the built-in Todo tile
            'todo' => [
                'open' => (clone $openTasks)->count(),
                'urgent' => (clone $openTasks)->whereNotNull('deadline')
                    ->whereDate('deadline', '<=', TodoService::today()->format('Y-m-d'))->count(),
            ],
        ]);
    }
}
