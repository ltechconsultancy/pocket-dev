<?php

namespace App\Http\Controllers;

use App\Services\Providers\OpenCodeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * OpenCode Go setup page. Login itself happens in a terminal
 * (`opencode auth login opencode`); this page shows the status.
 */
class OpenCodeAuthController extends Controller
{
    public function index()
    {
        return view('config.opencode', [
            'status' => OpenCodeProvider::authStatus(),
        ]);
    }

    /**
     * Fresh status check (also refreshes the cached login flag and model list).
     */
    public function status(): JsonResponse
    {
        $status = OpenCodeProvider::authStatus();
        Cache::put('opencode_logged_in', $status['logged_in'] || OpenCodeProvider::isLoggedIn(true), 60);
        Cache::forget('opencode_go_models');

        return response()->json($status + [
            'model_count' => count(OpenCodeProvider::discoverModels()),
        ]);
    }
}
