<?php

namespace App\Http\Controllers;

use App\Jobs\StartOpenCodeDeviceAuthJob;
use App\Services\Providers\OpenCodeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * OpenCode Go setup page. "Inloggen" starts a device login in the queue
 * container (StartOpenCodeDeviceAuthJob, same flow as Codex); the page polls
 * for the link + code and the final result.
 */
class OpenCodeAuthController extends Controller
{
    private const KEY = StartOpenCodeDeviceAuthJob::CACHE_KEY;

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

    public function startLogin(): JsonResponse
    {
        $existing = Cache::get(self::KEY);
        if ($existing && in_array($existing['status'] ?? '', ['starting', 'ready'], true)
            && ($existing['expires_at'] ?? 0) > time()) {
            return response()->json($this->sessionPayload($existing));
        }

        Cache::put(self::KEY, ['status' => 'starting', 'expires_at' => time() + 960], 960);
        StartOpenCodeDeviceAuthJob::dispatch();

        return response()->json(['status' => 'starting']);
    }

    public function loginStatus(): JsonResponse
    {
        $session = Cache::get(self::KEY);
        if (!$session) {
            return response()->json(['status' => 'none']);
        }
        if (in_array($session['status'] ?? '', ['starting', 'ready'], true) && ($session['expires_at'] ?? 0) <= time()) {
            Cache::forget(self::KEY);

            return response()->json(['status' => 'expired']);
        }

        return response()->json($this->sessionPayload($session));
    }

    public function cancelLogin(): JsonResponse
    {
        Cache::put(self::KEY, ['status' => 'cancelled'], 60);

        return response()->json(['status' => 'cancelled']);
    }

    public function logout(): JsonResponse
    {
        OpenCodeProvider::runCli('auth logout opencode', 20);
        OpenCodeProvider::isLoggedIn(true);
        Cache::forget('opencode_go_models');
        Cache::forget(self::KEY);

        return response()->json(OpenCodeProvider::authStatus());
    }

    private function sessionPayload(array $session): array
    {
        return [
            'status' => $session['status'] ?? 'none',
            'verification_url' => $session['verification_url'] ?? null,
            'user_code' => $session['user_code'] ?? null,
            'expires_in' => isset($session['expires_at']) ? max(0, $session['expires_at'] - time()) : null,
            'error' => $session['error'] ?? null,
        ];
    }
}
