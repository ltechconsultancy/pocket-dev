<?php

namespace App\Jobs;

use App\Services\Providers\OpenCodeProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OpenCode device login, same flow as StartCodexDeviceAuthJob: runs
 * `opencode auth login opencode --method device` in the queue container (appuser),
 * publishes the verification link + code via the cache and waits until the
 * CLI reports a saved OpenCode account.
 */
class StartOpenCodeDeviceAuthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CACHE_KEY = 'opencode_device_auth';

    /** Matches the login process but not the shell running pkill ("[a]" trick). */
    private const PROCESS_PATTERN = '[a]uth login opencode --method device';

    public int $timeout = 1000;

    public int $tries = 1;

    public function handle(): void
    {
        $bin = OpenCodeProvider::binaryPath();
        if ($bin === null) {
            $this->failWith('OpenCode CLI is niet geïnstalleerd. Vraag de AI om `npm install -g @opencode/cli` uit te voeren.');

            return;
        }

        // Only one login at a time
        exec('pkill -f ' . escapeshellarg(self::PROCESS_PATTERN) . ' 2>/dev/null');

        $logFile = sys_get_temp_dir() . '/opencode_device_auth_' . getmypid() . '.log';
        @unlink($logFile);

        // stdin from /dev/null: no TTY needed with --method device
        exec('nohup timeout 960 ' . escapeshellarg($bin) . ' auth login opencode --method device < /dev/null > '
            . escapeshellarg($logFile) . ' 2>&1 &');

        $url = null;
        $code = null;
        for ($i = 0; $i < 30 && !($url && $code); $i++) {
            sleep(1);
            $text = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', (string) @file_get_contents($logFile)) ?? '';
            if (!$url && preg_match('#(https://opencode\.ai/\S*device\S*)#i', $text, $m)) {
                $url = rtrim($m[1], '.,)');
            }
            if (!$code && preg_match('/\b([A-Z0-9]{4}-[A-Z0-9]{4})\b/', $text, $m)) {
                $code = $m[1];
            }
            if (!$url && preg_match('/(not found|error|failed)[^\n]*/i', $text, $m)) {
                $this->failWith('OpenCode-login kon niet starten: ' . trim($m[0]));
                @unlink($logFile);

                return;
            }
        }

        if (!$url || !$code) {
            Log::error('[OpenCode Device Auth] Could not parse URL/code', ['log' => @file_get_contents($logFile)]);
            $this->failWith('Kon geen inlogcode van OpenCode krijgen. Probeer het opnieuw.');
            @unlink($logFile);

            return;
        }

        $expiresAt = time() + 900;
        Cache::put(self::CACHE_KEY, [
            'status' => 'ready',
            'verification_url' => $url,
            'user_code' => $code,
            'expires_at' => $expiresAt,
        ], 960);
        Log::info('[OpenCode Device Auth] Device code ready', ['url' => $url]);

        // Wait for the CLI to save the account
        while (time() < $expiresAt) {
            sleep(3);

            if (OpenCodeProvider::authStatus()['logged_in']) {
                Cache::put(self::CACHE_KEY, ['status' => 'authenticated'], 120);
                OpenCodeProvider::isLoggedIn(true);
                Cache::forget('opencode_go_models');
                Log::info('[OpenCode Device Auth] Authentication successful');
                @unlink($logFile);

                return;
            }

            if ((Cache::get(self::CACHE_KEY)['status'] ?? null) === 'cancelled') {
                break;
            }
        }

        if ((Cache::get(self::CACHE_KEY)['status'] ?? null) !== 'cancelled') {
            Cache::put(self::CACHE_KEY, ['status' => 'expired'], 120);
        }
        exec('pkill -f ' . escapeshellarg(self::PROCESS_PATTERN) . ' 2>/dev/null');
        @unlink($logFile);
    }

    private function failWith(string $error): void
    {
        Cache::put(self::CACHE_KEY, ['status' => 'failed', 'error' => $error], 300);
        Log::error('[OpenCode Device Auth] ' . $error);
    }
}
