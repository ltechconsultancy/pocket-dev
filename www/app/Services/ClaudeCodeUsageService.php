<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeCodeUsageService
{
    private const CREDENTIALS_PATH = '/home/appuser/.claude/.credentials.json';
    private const CACHE_KEY = 'claude_code_usage_limits';
    private const CACHE_TTL = 300; // 5 minutes
    private const LAST_GOOD_KEY = 'claude_code_usage_limits_last_good';
    private const BACKOFF_KEY = 'claude_code_usage_limits_backoff';
    private const BACKOFF_SECONDS = 300; // wait at least 5 minutes after a failed call
    private const API_URL = 'https://api.anthropic.com/api/oauth/usage';

    /**
     * Check if a valid OAuth token exists.
     */
    public function hasValidToken(): bool
    {
        $creds = $this->readCredentials();

        if (!$creds) {
            return false;
        }

        return $creds['expiresAt'] > intval(now()->getPreciseTimestamp(3));
    }

    /**
     * Get Claude Code utilization data (5h/7d limits).
     * Returns null if unavailable.
     */
    public function getUtilization(): ?array
    {
        // Check credentials before caching to avoid locking out null for 5 min
        $creds = $this->readCredentials();
        if (!$creds || $creds['expiresAt'] < intval(now()->getPreciseTimestamp(3))) {
            return null;
        }

        $fresh = Cache::get(self::CACHE_KEY);
        if ($fresh !== null) {
            return $fresh;
        }

        // After a failure (e.g. 429) wait before calling again: without this every open tab
        // retried immediately and kept the rate limit alive. Meanwhile serve the last good data.
        if (Cache::has(self::BACKOFF_KEY)) {
            return Cache::get(self::LAST_GOOD_KEY);
        }

        try {
            $response = Http::withToken($creds['accessToken'])
                ->withHeaders([
                    'anthropic-beta' => 'oauth-2025-04-20',
                ])
                ->timeout(5)
                ->get(self::API_URL);

            if ($response->ok() && is_array($data = $response->json())) {
                Cache::put(self::CACHE_KEY, $data, self::CACHE_TTL);
                Cache::put(self::LAST_GOOD_KEY, $data, now()->addDay());

                return $data;
            }

            $retryAfter = (int) $response->header('Retry-After');
            $backoff = max(self::BACKOFF_SECONDS, min($retryAfter, 3600));
            Log::warning('Claude Code usage API returned non-OK', [
                'status' => $response->status(),
                'backoff_seconds' => $backoff,
            ]);
        } catch (\Exception $e) {
            $backoff = self::BACKOFF_SECONDS;
            Log::warning('Claude Code usage API request failed', [
                'error' => $e->getMessage(),
            ]);
        }

        Cache::put(self::BACKOFF_KEY, true, $backoff);

        return Cache::get(self::LAST_GOOD_KEY);
    }

    /**
     * Store utilization from a Claude Code CLI `rate_limit_event` (sent on every turn):
     * {"unifiedWindows": {"five_hour": {"utilization": 0.41, "resetsAt": 1792709400}, "seven_day": {...}}}
     * Converted to the usage API shape (utilization in percent, resets_at ISO 8601).
     */
    public function recordFromCli(array $info): void
    {
        $windows = $info['unifiedWindows'] ?? null;
        if (!is_array($windows)) {
            return;
        }

        $data = Cache::get(self::CACHE_KEY) ?? Cache::get(self::LAST_GOOD_KEY) ?? [];
        $found = false;
        foreach (['five_hour', 'seven_day'] as $key) {
            $w = $windows[$key] ?? null;
            if (!is_array($w) || !is_numeric($w['utilization'] ?? null)) {
                continue;
            }
            $data[$key] = [
                'utilization' => round((float) $w['utilization'] * 100, 1),
                'resets_at' => is_numeric($w['resetsAt'] ?? null)
                    ? \Carbon\Carbon::createFromTimestamp((int) $w['resetsAt'])->toIso8601String()
                    : ($data[$key]['resets_at'] ?? null),
            ];
            $found = true;
        }
        if (!$found) {
            return;
        }

        Cache::put(self::CACHE_KEY, $data, self::CACHE_TTL);
        Cache::put(self::LAST_GOOD_KEY, $data, now()->addDay());
    }

    /**
     * Force refresh the cached utilization data.
     */
    public function refresh(): ?array
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::BACKOFF_KEY);

        return $this->getUtilization();
    }

    /**
     * Read OAuth credentials from the Claude credentials file.
     */
    private function readCredentials(): ?array
    {
        if (!file_exists(self::CREDENTIALS_PATH)) {
            return null;
        }

        try {
            // Try direct read first (works if file is group-readable)
            $contents = @file_get_contents(self::CREDENTIALS_PATH);

            // If permission denied, use SUID helper binary (always runs as root)
            // Install via: pd system:package add --name="read-claude-creds" ...
            if ($contents === false && is_executable('/usr/local/bin/read-claude-creds')) {
                $contents = @shell_exec('/usr/local/bin/read-claude-creds 2>/dev/null');
            }

            if (!$contents) {
                Log::warning('Cannot read Claude credentials file (permission denied)');
                return null;
            }

            $data = json_decode($contents, true);

            if (!$data || !isset($data['claudeAiOauth']['accessToken'])) {
                return null;
            }

            return [
                'accessToken' => $data['claudeAiOauth']['accessToken'],
                'expiresAt' => $data['claudeAiOauth']['expiresAt'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to read Claude credentials', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
