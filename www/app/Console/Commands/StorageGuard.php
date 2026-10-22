<?php

namespace App\Console\Commands;

use App\Jobs\SendPushNotification;
use App\Services\StorageJanitor;
use Illuminate\Console\Command;

/**
 * Disk guard: meant to be called by the host's pd-disk-guard timer (every 15 minutes),
 * e.g. `docker exec -u appuser pocket-dev-queue php /var/www/artisan storage:guard`.
 * Not scheduled inside the container on purpose: one check, one place.
 *
 * - >= warn %:     normal cleanup (tmp, logs, caches; never chat uploads)
 * - >= critical %: aggressive cleanup incl. stopped containers / unused images
 * - always:        start supervisor programs stuck in FATAL when there is room
 * - push notification on warn/critical (at most once per 6 hours per level)
 * Exit code: 0 ok, 1 warn, 2 still critical after cleanup.
 */
class StorageGuard extends Command
{
    protected $signature = 'storage:guard
        {--warn=80 : Used % that triggers a normal cleanup}
        {--critical=90 : Used % that triggers an aggressive cleanup}
        {--no-cleanup : Only check and heal workers}';

    protected $description = 'Check disk usage, clean up when needed and restart FATAL workers';

    public function handle(): int
    {
        $janitor = new StorageJanitor();
        $disk = StorageJanitor::disk();
        $warn = (float) $this->option('warn');
        $critical = (float) $this->option('critical');
        $level = $disk['used_percent'] >= $critical ? 'critical' : ($disk['used_percent'] >= $warn ? 'warn' : 'ok');

        $cleanup = null;
        if ($level !== 'ok' && !$this->option('no-cleanup')) {
            $aggressive = $level === 'critical';
            $cleanup = (new StorageJanitor($aggressive))->run(false, $aggressive
                ? ['tmp', 'logs', 'caches', 'docker']
                : ['tmp', 'logs', 'caches']);
        }

        $heal = $janitor->healWorkers();
        $after = StorageJanitor::disk();
        $levelAfter = $after['used_percent'] >= $critical ? 'critical' : ($after['used_percent'] >= $warn ? 'warn' : 'ok');

        $janitor->writeStatus(['last_guard' => [
            'at' => now()->toIso8601String(),
            'level_before' => $level,
            'level_after' => $levelAfter,
            'disk_before' => $disk,
            'disk_after' => $after,
            'freed_bytes' => $cleanup['freed_bytes'] ?? 0,
            'healed' => $heal['started'] ?? [],
        ]]);

        if ($level !== 'ok') {
            $this->notify($levelAfter === 'ok' ? $level : $levelAfter, $disk, $after, $cleanup);
        }

        $this->line(sprintf('storage:guard %s → %s | %s%% → %s%% vol | vrijgemaakt %s | workers gestart: %d',
            $level, $levelAfter, $disk['used_percent'], $after['used_percent'],
            StorageCleanup::size($cleanup['freed_bytes'] ?? 0), count($heal['started'] ?? [])));
        foreach ($heal['started'] ?? [] as $s) {
            $this->line('  gestart: ' . $s['program'] . ' → ' . $s['result']);
        }

        return match ($levelAfter) {
            'critical' => 2,
            'warn' => 1,
            default => 0,
        };
    }

    private function notify(string $level, array $before, array $after, ?array $cleanup): void
    {
        $status = StorageJanitor::status();
        $last = $status['last_notified'][$level] ?? null;
        if ($last && now()->diffInHours(\Carbon\Carbon::parse($last), true) < 6) {
            return;
        }

        try {
            SendPushNotification::dispatchSync(
                userId: \App\Models\User::first()?->id,
                title: $level === 'critical' ? 'Schijf bijna vol' : 'Schijf raakt vol',
                body: sprintf('%s%% → %s%% vol na opruimen (%s vrijgemaakt). %s',
                    $before['used_percent'], $after['used_percent'],
                    StorageCleanup::size($cleanup['freed_bytes'] ?? 0),
                    $level === 'critical' ? 'Handmatig ingrijpen nodig.' : 'Automatisch opgeruimd.'),
                url: '/config/usage',
            );
            (new StorageJanitor())->writeStatus(['last_notified' => array_merge($status['last_notified'] ?? [], [$level => now()->toIso8601String()])]);
        } catch (\Throwable $e) {
            $this->warn('Pushmelding mislukt: ' . $e->getMessage());
        }
    }
}
