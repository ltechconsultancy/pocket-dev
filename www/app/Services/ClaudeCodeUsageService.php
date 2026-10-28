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
                $this->storeForAccount(app(ClaudeAccountService::class)->activeId(), $data);

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
        $this->storeForAccount(app(ClaudeAccountService::class)->activeId(), $data);
    }

    // ===== Per account (one ring per saved account in the header) =====

    private const ACCOUNT_KEY = 'claude_usage_acct:';
    private const ACCOUNT_BACKOFF_KEY = 'claude_usage_acct_backoff:';
    private const ACCOUNT_REFRESH_AFTER = 600; // inactive accounts: ask the API at most every 10 min

    /**
     * Usage of every saved account. Active account: live data (CLI events / API). Inactive accounts:
     * their own saved token against the usage API (only while that access token is still valid;
     * PocketDev never refreshes an inactive account's token itself), else the last known values.
     *
     * @return array<int, array>
     */
    public function forAccounts(): array
    {
        $accounts = app(ClaudeAccountService::class);
        $out = [];
        foreach ($accounts->withTokens() as $acc) {
            if ($acc['active']) {
                $data = $this->getUtilization();
                if ($data) {
                    $this->storeForAccount($acc['id'], $data);
                }
            } else {
                $this->refreshInactive($acc);
            }
            $stored = Cache::get(self::ACCOUNT_KEY . $acc['id']);
            $out[] = [
                'id' => $acc['id'],
                'label' => $acc['label'],
                'email' => $acc['email'],
                'short' => $acc['short'],
                'active' => $acc['active'],
                'five_hour' => $stored['data']['five_hour'] ?? null,
                'seven_day' => $stored['data']['seven_day'] ?? null,
                'fetched_at' => $stored['fetched_at'] ?? null,
            ];
        }

        return $out;
    }

    private function refreshInactive(array $acc): void
    {
        $stored = Cache::get(self::ACCOUNT_KEY . $acc['id']);
        $fresh = $stored && now()->diffInSeconds(\Carbon\Carbon::parse($stored['fetched_at']), true) < self::ACCOUNT_REFRESH_AFTER;
        $tokenValid = $acc['access_token'] && ($acc['expires_at'] ?? 0) > intval(now()->getPreciseTimestamp(3)) + 60000;
        if ($fresh || !$tokenValid || Cache::has(self::ACCOUNT_BACKOFF_KEY . $acc['id'])) {
            return;
        }

        try {
            $response = Http::withToken($acc['access_token'])
                ->withHeaders(['anthropic-beta' => 'oauth-2025-04-20'])
                ->timeout(5)
                ->get(self::API_URL);
            if ($response->ok() && is_array($data = $response->json())) {
                $this->storeForAccount($acc['id'], $data);

                return;
            }
            $backoff = max(self::BACKOFF_SECONDS, min((int) $response->header('Retry-After'), 3600));
        } catch (\Exception) {
            $backoff = self::BACKOFF_SECONDS;
        }
        Cache::put(self::ACCOUNT_BACKOFF_KEY . $acc['id'], true, $backoff);
    }

    private function storeForAccount(?string $id, array $data): void
    {
        if (!$id || (!isset($data['five_hour']) && !isset($data['seven_day']))) {
            return;
        }
        Cache::put(self::ACCOUNT_KEY . $id, ['data' => $data, 'fetched_at' => now()->toIso8601String()], now()->addDays(8));
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
