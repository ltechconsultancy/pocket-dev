<?php

namespace App\Services;

use App\Support\AppUserFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Several Claude Code (subscription) accounts, one active at a time.
 *
 * The CLI only knows ~/.claude/.credentials.json. Every saved account is a copy of that file in
 * ~/.claude/pocketdev-accounts/{id}.json, plus index.json with labels and which one is active.
 *
 * - Switch: first write the live file back into the active slot (the CLI rotates refresh tokens,
 *   so the live file is always the freshest copy), then put the chosen slot in place.
 * - Add: park the live file in its slot and remove it, so the user can log in with another
 *   account (`claude` /login, or upload on /claude/auth). The next list() adopts the new login.
 * - Running conversations keep the token they already loaded; new turns use the new account.
 */
class ClaudeAccountService
{
    private const PROFILE_URL = 'https://api.anthropic.com/api/oauth/profile';

    private string $credentialsPath;
    private string $dir;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $this->credentialsPath = "{$home}/.claude/.credentials.json";
        $this->dir = "{$home}/.claude/pocketdev-accounts";
    }

    /**
     * Accounts for the UI. Adopts a fresh login (after "add account") or a first-time setup.
     *
     * @return array{accounts: array<int, array>, active_id: ?string, pending_login: bool}
     */
    /** Serialise all account changes: several tabs poll list(), which may adopt a new login. */
    public function locked(callable $fn): array
    {
        return Cache::lock('claude-accounts', 15)->block(10, $fn);
    }

    public function list(): array
    {
        $index = $this->readIndex();
        $live = $this->readLive();

        if ($live && !$index['active_id']) {
            // A login happened while no slot was active: first use, or "add account" was clicked
            $index = $this->adopt($index, $live);
        }

        $accounts = array_map(function (array $a) use ($index, $live) {
            $isActive = $a['id'] === $index['active_id'];
            $creds = $isActive ? $live : $this->readSlot($a['id']);
            $oauth = $creds['claudeAiOauth'] ?? [];

            return [
                'id' => $a['id'],
                'label' => $a['label'],
                'email' => $a['email'] ?? null,
                'subscription' => $oauth['subscriptionType'] ?? null,
                'active' => $isActive,
                'missing' => !$creds,
            ];
        }, $index['accounts']);

        return [
            'accounts' => $accounts,
            'active_id' => $index['active_id'],
            'pending_login' => !$index['active_id'] && !empty($index['accounts']),
        ];
    }

    public function switchTo(string $id): array
    {
        $index = $this->readIndex();
        $target = $this->find($index, $id);
        if ($index['active_id'] === $id) {
            return $this->list();
        }

        $targetCreds = $this->readSlot($id);
        if (!$targetCreds) {
            throw new RuntimeException('De opgeslagen inlog van dit account ontbreekt; log opnieuw in en voeg het opnieuw toe.');
        }

        $this->parkLive($index);
        AppUserFiles::write($this->credentialsPath, json_encode($targetCreds, JSON_PRETTY_PRINT));
        $index['active_id'] = $target['id'];
        $this->writeIndex($index);
        $this->forgetUsage();

        Log::info('[Claude accounts] Switched account', ['id' => $id, 'label' => $target['label']]);

        return $this->list();
    }

    /** Park the current account and clear the live login, so another account can log in. */
    public function beginAdd(): array
    {
        $index = $this->readIndex();
        if (!$index['active_id'] && ($live = $this->readLive())) {
            $index = $this->adopt($index, $live);
        }
        $this->parkLive($index);
        AppUserFiles::delete($this->credentialsPath);
        $index['previous_id'] = $index['active_id'];
        $index['active_id'] = null;
        $this->writeIndex($index);
        $this->forgetUsage();

        return $this->list();
    }

    /** Back to the previous account when "add" is cancelled. */
    public function cancelAdd(): array
    {
        $index = $this->readIndex();
        if ($index['active_id'] || $this->readLive() || empty($index['accounts'])) {
            return $this->list();
        }
        $ids = array_column($index['accounts'], 'id');
        $previous = in_array($index['previous_id'] ?? null, $ids, true) ? $index['previous_id'] : end($ids);
        $creds = $this->readSlot($previous);
        if ($creds) {
            AppUserFiles::write($this->credentialsPath, json_encode($creds, JSON_PRETTY_PRINT));
            $index['active_id'] = $previous;
            $this->writeIndex($index);
        }

        return $this->list();
    }

    public function rename(string $id, string $label): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 60) {
            throw new RuntimeException('Naam is verplicht (max 60 tekens).');
        }
        $index = $this->readIndex();
        $this->find($index, $id);
        foreach ($index['accounts'] as &$a) {
            if ($a['id'] === $id) {
                $a['label'] = $label;
            }
        }
        unset($a);
        $this->writeIndex($index);

        return $this->list();
    }

    public function remove(string $id): array
    {
        $index = $this->readIndex();
        $this->find($index, $id);
        if ($index['active_id'] === $id) {
            throw new RuntimeException('Dit is het actieve account. Wissel eerst naar een ander account.');
        }
        AppUserFiles::delete($this->slotPath($id));
        $index['accounts'] = array_values(array_filter($index['accounts'], fn($a) => $a['id'] !== $id));
        $this->writeIndex($index);

        return $this->list();
    }

    // ===== Internals =====

    private function adopt(array $index, array $live): array
    {
        $profile = $this->fetchProfile($live['claudeAiOauth']['accessToken'] ?? '');
        $email = $profile['email'] ?? null;

        // Same account logged in again (e.g. re-login after "add"): reuse its slot
        $existing = $email ? collect($index['accounts'])->firstWhere('email', $email) : null;
        if ($existing) {
            $id = $existing['id'];
        } else {
            $id = (string) Str::uuid();
            $index['accounts'][] = [
                'id' => $id,
                'label' => $profile['name'] ?? $email ?? ('Account ' . (count($index['accounts']) + 1)),
                'email' => $email,
                'added_at' => now()->toIso8601String(),
            ];
        }

        AppUserFiles::write($this->slotPath($id), json_encode($live, JSON_PRETTY_PRINT));
        $index['active_id'] = $id;
        $this->writeIndex($index);

        return $index;
    }

    /** Save the live credentials into the active slot (they are newer than the slot copy). */
    private function parkLive(array $index): void
    {
        $live = $this->readLive();
        if ($live && $index['active_id']) {
            AppUserFiles::write($this->slotPath($index['active_id']), json_encode($live, JSON_PRETTY_PRINT));
        }
    }

    private function fetchProfile(string $token): array
    {
        if ($token === '') {
            return [];
        }
        try {
            $response = Http::withToken($token)
                ->withHeaders(['anthropic-beta' => 'oauth-2025-04-20'])
                ->timeout(5)
                ->get(self::PROFILE_URL);
            if (!$response->ok()) {
                return [];
            }
            $account = $response->json('account') ?? [];
            $org = $response->json('organization') ?? [];

            return array_filter([
                'email' => $account['email_address'] ?? $account['email'] ?? null,
                'name' => $account['email_address'] ?? $account['display_name'] ?? $org['name'] ?? null,
            ]);
        } catch (\Throwable) {
            return [];
        }
    }

    private function find(array $index, string $id): array
    {
        foreach ($index['accounts'] as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }
        throw new RuntimeException('Account niet gevonden.');
    }

    private function readLive(): ?array
    {
        $data = json_decode((string) AppUserFiles::read($this->credentialsPath), true);

        return is_array($data) && !empty($data['claudeAiOauth']['refreshToken']) ? $data : null;
    }

    private function readSlot(string $id): ?array
    {
        $data = json_decode((string) AppUserFiles::read($this->slotPath($id)), true);

        return is_array($data) && !empty($data['claudeAiOauth']) ? $data : null;
    }

    private function slotPath(string $id): string
    {
        if (!Str::isUuid($id)) {
            throw new RuntimeException('Ongeldig account-id.');
        }

        return "{$this->dir}/{$id}.json";
    }

    private function readIndex(): array
    {
        $data = json_decode((string) AppUserFiles::read("{$this->dir}/index.json"), true);

        return [
            'accounts' => array_values(array_filter($data['accounts'] ?? [], fn($a) => is_array($a) && Str::isUuid($a['id'] ?? ''))),
            'active_id' => $data['active_id'] ?? null,
            'previous_id' => $data['previous_id'] ?? null,
        ];
    }

    private function writeIndex(array $index): void
    {
        AppUserFiles::write("{$this->dir}/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Usage rings are per account. */
    private function forgetUsage(): void
    {
        foreach (['claude_code_usage_limits', 'claude_code_usage_limits_last_good', 'claude_code_usage_limits_backoff'] as $key) {
            Cache::forget($key);
        }
    }
}
