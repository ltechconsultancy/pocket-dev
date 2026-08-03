<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CursorAuthController extends Controller
{
    protected string $credentialsPath;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $this->credentialsPath = "{$home}/.config/cursor/auth.json";
    }

    /**
     * Show the authentication status page.
     */
    public function index()
    {
        return view("cursor-auth", [
            "status" => $this->getAuthenticationStatus(),
            "uploadToken" => $this->createUploadToken(),
        ]);
    }

    /**
     * Get current authentication status.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->getAuthenticationStatus());
    }

    /**
     * Upload credentials from JSON text.
     */
    public function uploadJson(Request $request): JsonResponse
    {
        // For terminal uploads, capture the token but defer consumption (Cache::pull)
        // until validation passes — a malformed paste should NOT burn the token.
        // Pulling after validation keeps one-shot semantics intact (no TOCTOU window
        // where two concurrent requests both pass a `has` check).
        $isApiUpload = $request->routeIs('cursor.auth.apiUpload');
        $uploadTokenKey = null;
        if ($isApiUpload) {
            $uploadToken = $request->input('upload_token');
            if (!is_string($uploadToken) || $uploadToken === ''
                || !Cache::has('cursor_auth_upload:' . $uploadToken)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired upload token. Open /cursor/auth and copy a fresh command.',
                ], 403);
            }
            $uploadTokenKey = 'cursor_auth_upload:' . $uploadToken;
        }

        $validator = Validator::make($request->all(), [
            "json" => "required|string",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => "JSON content is required.",
                "errors" => $validator->errors(),
            ], 422);
        }

        try {
            $data = json_decode($request->input("json"), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    "success" => false,
                    "message" => "Invalid JSON: " . json_last_error_msg(),
                ], 422);
            }

            // Validate structure
            if (!$this->isValidCredentialFile($data)) {
                return response()->json([
                    "success" => false,
                    "message" => "Invalid credentials structure. Expected accessToken and refreshToken.",
                ], 422);
            }

            // Create directory if it does not exist
            $dir = dirname($this->credentialsPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }

            // Save the file. LOCK_EX serializes concurrent PHP writers; it does NOT
            // coordinate with the `agent` CLI binary (which does not honor advisory locks).
            $bytes = file_put_contents($this->credentialsPath, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
            if ($bytes === false) {
                // Token is intentionally NOT consumed — user can retry without
                // returning to /cursor/auth for a fresh command. Path is logged
                // server-side but kept out of the client response.
                Log::error('[Cursor Auth] Write failed', ['path' => $this->credentialsPath]);
                throw new \RuntimeException('Failed to write credentials file.');
            }

            // Try to set group-writable permissions (non-fatal if we're not the owner)
            @chmod($dir, 0770);
            @chmod($this->credentialsPath, 0660);

            // Atomically consume the one-time token only after the write succeeded.
            // If a concurrent request already pulled it, surface the same 403 — the
            // file write was a no-op duplicate (LOCK_EX serialized us with them).
            if ($uploadTokenKey !== null && !Cache::pull($uploadTokenKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload token already used. The credentials were saved by a concurrent request.',
                ], 403);
            }

            // New credentials may belong to a different account → drop cached model lists
            Cache::forget('cursor_agent:models');
            Cache::forget('cursor_agent:all_model_ids');

            Log::info("[Cursor Auth] Credentials saved from JSON input");

            return response()->json([
                "success" => true,
                "message" => "Credentials saved successfully.",
                "status" => $this->getAuthenticationStatus(),
            ]);

        } catch (\Exception $e) {
            // Log full detail server-side; return a generic message so we don't
            // leak filesystem paths or other internals to the client.
            Log::error("[Cursor Auth] Failed to save credentials from JSON", [
                "error" => $e->getMessage(),
            ]);

            return response()->json([
                "success" => false,
                "message" => "Failed to save credentials.",
            ], 500);
        }
    }

    /**
     * Clear credentials (logout).
     */
    public function logout(): JsonResponse
    {
        try {
            // Run `agent logout` to clear any cached state
            $home = getenv('HOME') ?: '/home/appuser';
            $agentPath = "{$home}/.local/bin/agent";
            if (is_executable($agentPath)) {
                exec(escapeshellarg($agentPath) . ' logout 2>/dev/null');
            }

            if (file_exists($this->credentialsPath)) {
                unlink($this->credentialsPath);
                Log::info("[Cursor Auth] Credentials cleared");
            }

            // Also clear API key if stored
            $settings = app(AppSettingsService::class);
            if ($settings->hasCursorAgentApiKey()) {
                $settings->deleteCursorAgentApiKey();
            }

            // Drop cached model lists — they're per-account and may change after re-auth
            Cache::forget('cursor_agent:models');
            Cache::forget('cursor_agent:all_model_ids');

            return response()->json([
                "success" => true,
                "message" => "Logged out successfully.",
            ]);

        } catch (\Exception $e) {
            Log::error("[Cursor Auth] Failed to clear credentials", [
                "error" => $e->getMessage(),
            ]);

            return response()->json([
                "success" => false,
                "message" => "Failed to clear credentials: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start browser auth flow - dispatches a background job that runs `agent login`.
     */
    public function startBrowserAuth(): JsonResponse
    {
        // Already authenticated
        if (file_exists($this->credentialsPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Already authenticated. Logout first if you want to switch accounts.',
            ], 400);
        }

        // Check if there's already an active session in progress
        $existing = Cache::get('cursor_browser_auth');
        if ($existing && in_array($existing['status'], ['starting', 'ready'], true)) {
            $expiresAt = $existing['expires_at'] ?? 0;
            if ($expiresAt > time()) {
                return response()->json([
                    'success' => true,
                    'status' => $existing['status'],
                    'verification_url' => $existing['verification_url'] ?? null,
                    'expires_in' => max(0, $expiresAt - time()),
                ]);
            }
        }

        // Store initial "starting" state
        Cache::put('cursor_browser_auth', [
            'status' => 'starting',
            'started_at' => time(),
            'expires_at' => time() + 960,
        ], 960);

        // Dispatch the long-running job
        \App\Jobs\StartCursorBrowserAuthJob::dispatch();

        Log::info('[Cursor Browser Auth] Job dispatched');

        return response()->json([
            'success' => true,
            'status' => 'starting',
        ]);
    }

    /**
     * Return the current browser auth session status.
     */
    public function browserAuthStatus(): JsonResponse
    {
        // Auth file already exists
        if (file_exists($this->credentialsPath)) {
            Cache::forget('cursor_browser_auth');

            return response()->json([
                'success' => true,
                'status' => 'authenticated',
            ]);
        }

        $session = Cache::get('cursor_browser_auth');

        if (!$session) {
            return response()->json(['success' => true, 'status' => 'none']);
        }

        // Check expiry
        if (($session['expires_at'] ?? 0) <= time()) {
            Cache::forget('cursor_browser_auth');
            return response()->json(['success' => true, 'status' => 'expired']);
        }

        // Propagate job-reported "authenticated" status
        if ($session['status'] === 'authenticated') {
            return response()->json([
                'success' => true,
                'status' => 'authenticated',
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $session['status'],
            'verification_url' => $session['verification_url'] ?? null,
            'expires_in' => max(0, ($session['expires_at'] ?? 0) - time()),
            'error' => $session['error'] ?? null,
        ]);
    }

    /**
     * One-time token for CSRF-exempt terminal upload (embedded in copied curl commands).
     */
    protected function createUploadToken(): string
    {
        $token = Str::random(64);
        Cache::put('cursor_auth_upload:' . $token, true, 900);

        return $token;
    }

    /**
     * Get current authentication status.
     */
    protected function getAuthenticationStatus(): array
    {
        // Check API key first
        $settings = app(AppSettingsService::class);
        if ($settings->hasCursorAgentApiKey()) {
            $key = $settings->getCursorAgentApiKey();
            return [
                "authenticated" => true,
                "auth_type" => "api_key",
                "key_preview" => substr($key, 0, 8) . "..." . substr($key, -4),
            ];
        }

        if (!file_exists($this->credentialsPath)) {
            return [
                "authenticated" => false,
                "message" => "Not authenticated",
            ];
        }

        try {
            // Use `agent status --format json` for a reliable check
            $home = getenv('HOME') ?: '/home/appuser';
            $agentPath = "{$home}/.local/bin/agent";

            if (is_executable($agentPath)) {
                $output = [];
                $returnCode = 0;
                exec(escapeshellarg($agentPath) . ' status --format json 2>/dev/null', $output, $returnCode);

                if ($returnCode === 0 && !empty($output)) {
                    $statusData = json_decode(implode('', $output), true);
                    if (is_array($statusData)) {
                        $isAuth = $statusData['isAuthenticated'] ?? false;
                        $email = $statusData['email'] ?? null;

                        if ($isAuth) {
                            return [
                                "authenticated" => true,
                                "auth_type" => "subscription",
                                "email" => $email,
                            ];
                        }
                    }
                }
            }

            // Fallback: check file contents directly
            $content = file_get_contents($this->credentialsPath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    "authenticated" => false,
                    "message" => "Invalid credentials file",
                ];
            }

            if (!empty($data['accessToken']) && !empty($data['refreshToken'])) {
                return [
                    "authenticated" => true,
                    "auth_type" => "subscription",
                ];
            }

            return [
                "authenticated" => false,
                "message" => "Unknown credentials format",
            ];

        } catch (\Exception $e) {
            Log::error("[Cursor Auth] Failed to read credentials", [
                "error" => $e->getMessage(),
            ]);

            return [
                "authenticated" => false,
                "message" => "Error reading credentials",
                "error" => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate credential file structure.
     */
    protected function isValidCredentialFile(array $data): bool
    {
        // Valid if has accessToken and refreshToken (Cursor OAuth format)
        if (!empty($data['accessToken']) && !empty($data['refreshToken'])) {
            return true;
        }

        return false;
    }
}
