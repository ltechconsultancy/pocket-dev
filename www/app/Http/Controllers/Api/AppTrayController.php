<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppTrayItem;
use Illuminate\Http\JsonResponse;

/**
 * Read-only list for the App Tray panel (items are managed via `pd app-tray`).
 */
class AppTrayController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'items' => AppTrayItem::ordered()->get()->map->toTrayArray()->values(),
        ]);
    }
}
