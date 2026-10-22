<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Keeps the shared disk from filling up (it did several times: /tmp, caches, logs,
 * stopped containers). Used by `storage:cleanup` (daily) and `storage:guard`
 * (called by the host's pd-disk-guard timer every 15 minutes).
 *
 * Safety rules (never broken, also not in aggressive mode):
 * - Nothing that a process uses: paths under any /proc/<pid>/cwd, exe or open fd are skipped.
 * - No sockets/pipes, and nothing touched within the age window (newest file inside counts).
 * - PocketDev's own containers are never removed; only stopped containers are.
 * - Projects (.venv, node_modules, ...) are never removed automatically: only reported.
 * - Chat uploads (/tmp/pocketdev-uploads) and conversations are never removed.
 * Every action is planned first; dry-run returns the plan without touching anything.
 */
class StorageJanitor
{
    public const STATUS_FILE = 'storage-janitor.json';

    /** /tmp entries that must stay regardless of age. */
    private const TMP_KEEP = ['supervisor.sock', '.X11-unix', '.ICE-unix', '.font-unix', '.XIM-unix', 'pocketdev-uploads', 'claude-1000'];

    private array $inUse = [];

    private bool $dryRun = true;

    /** @var array<int, array{category: string, path: string, bytes: int, age_days: ?float, action: string, done?: bool, error?: string}> */
    private array $plan = [];

    public function __construct(private bool $aggressive = false)
    {
    }

    // ------------------------------------------------------------------ public API

    /**
     * Plan (and unless dry-run, execute) the cleanup.
     *
     * @param  array<int, string>  $parts  tmp, logs, caches, docker (chat uploads are never touched)
     */
    public function run(bool $dryRun, array $parts = ['tmp', 'logs', 'caches']): array
    {
        $this->dryRun = $dryRun;
        $this->plan = [];
        $this->inUse = $this->pathsInUse();
        $before = self::disk();

        foreach ($parts as $part) {
            match ($part) {
                'tmp' => $this->planTmp(),
                'logs' => $this->planLogs(),
                'caches' => $this->planCaches(),
                'docker' => $this->planDocker(),
                default => null,
            };
        }

        if (!$dryRun) {
            foreach ($this->plan as $i => $item) {
                $this->plan[$i] = $this->execute($item);
            }
        }

        $after = self::disk();
        $report = [
            'at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'aggressive' => $this->aggressive,
            'parts' => $parts,
            'disk_before' => $before,
            'disk_after' => $after,
            'planned_bytes' => array_sum(array_column($this->plan, 'bytes')),
            'freed_bytes' => $dryRun ? 0 : max(0, $after['free'] - $before['free']),
            'items' => $this->plan,
        ];
        if (!$dryRun) {
            $this->writeStatus(['last_cleanup' => array_diff_key($report, ['items' => 1]) + ['count' => count($this->plan)]]);
        }

        return $report;
    }

    /**
     * Report-only: dependency folders in projects untouched for $days, with in-use check.
     * Never deleted automatically (rebuilding is not guaranteed: private registries, pins).
     */
    public function reportProjectDependencies(int $days = 30): array
    {
        $this->inUse = $this->pathsInUse();
        $found = [];
        $cmd = 'find /workspace/default -xdev -maxdepth 4 -type d \( -name node_modules -o -name .venv -o -name venv -o -name .gradle -o -name target \) -prune -print 2>/dev/null';
        foreach (array_filter(explode("\n", (string) shell_exec($cmd))) as $dir) {
            $project = dirname($dir);
            $newest = $this->newestMtime($project, exclude: $dir);
            $age = $newest ? (time() - $newest) / 86400 : null;
            if ($age !== null && $age < $days) {
                continue;
            }
            $found[] = [
                'path' => $dir,
                'bytes' => $this->du($dir),
                'project_age_days' => $age !== null ? round($age, 1) : null,
                'in_use' => $this->isInUse($dir),
            ];
        }
        usort($found, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        return $found;
    }

    /**
     * Supervisor programs in FATAL (end state, never restarted by supervisor itself):
     * start them again when there is room. Returns what was started.
     */
    public function healWorkers(float $minFreePercent = 5.0): array
    {
        $disk = self::disk();
        if ($disk['free_percent'] < $minFreePercent) {
            return ['skipped' => 'te weinig ruimte (' . $disk['free_percent'] . '% vrij)'];
        }

        $sock = self::supervisorSocket();
        if ($sock === null) {
            return ['skipped' => 'geen supervisor-socket'];
        }

        $started = [];
        $status = (string) shell_exec('supervisorctl -s unix://' . escapeshellarg($sock) . ' status 2>&1');
        foreach (preg_split('/\R/', $status) as $line) {
            if (preg_match('/^(\S+)\s+FATAL\b/', $line, $m)) {
                $out = trim((string) shell_exec('supervisorctl -s unix://' . escapeshellarg($sock) . ' start ' . escapeshellarg($m[1]) . ' 2>&1'));
                $started[] = ['program' => $m[1], 'result' => $out];
            }
        }

        return ['started' => $started];
    }

    public static function disk(string $path = '/workspace'): array
    {
        $total = @disk_total_space($path) ?: 1;
        $free = @disk_free_space($path) ?: 0;

        return [
            'total' => (int) $total,
            'free' => (int) $free,
            'used_percent' => round(100 - $free / $total * 100, 1),
            'free_percent' => round($free / $total * 100, 1),
        ];
    }

    public function writeStatus(array $data): void
    {
        $file = storage_path('app/' . self::STATUS_FILE);
        $current = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, json_encode(array_merge($current, $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($file, 0664);
    }

    public static function status(): array
    {
        $file = storage_path('app/' . self::STATUS_FILE);

        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    // ------------------------------------------------------------------ planning

    /** /tmp: top-level entries whose newest file is older than the window. */
    private function planTmp(): void
    {
        $days = $this->aggressive ? 2 : 7;
        $entries = [];
        // One pass over /tmp: per top-level entry the newest mtime, total size, and special files
        $find = popen('find /tmp -xdev -mindepth 1 -printf ' . escapeshellarg('%P\t%T@\t%s\t%y\n') . ' 2>/dev/null', 'r');
        while ($find && ($line = fgets($find)) !== false) {
            [$rel, $mtime, $size, $type] = array_pad(explode("\t", rtrim($line, "\n")), 4, '');
            $top = explode('/', $rel, 2)[0];
            $e = $entries[$top] ?? ['mtime' => 0, 'bytes' => 0, 'special' => false];
            $e['mtime'] = max($e['mtime'], (float) $mtime);
            $e['bytes'] += (int) $size;
            $e['special'] = $e['special'] || in_array($type, ['s', 'p', 'c', 'b'], true);
            $entries[$top] = $e;
        }
        if ($find) {
            pclose($find);
        }

        foreach ($entries as $name => $e) {
            $path = '/tmp/' . $name;
            $age = (time() - $e['mtime']) / 86400;
            if (in_array($name, self::TMP_KEEP, true) || $e['special'] || $age < $days || $this->isInUse($path)) {
                continue;
            }
            $this->plan[] = ['category' => 'tmp', 'path' => $path, 'bytes' => $e['bytes'], 'age_days' => round($age, 1), 'action' => 'rm'];
        }
    }

    private function planLogs(): void
    {
        $logs = storage_path('logs');
        $this->planOldFiles('logs', $logs . '/conversations', $this->aggressive ? 3 : 14);
        $this->planOldFiles('logs', $logs . '/request-flow', $this->aggressive ? 2 : 7);
        foreach (glob($logs . '/*.log') ?: [] as $file) {
            $age = (time() - filemtime($file)) / 86400;
            if ($age >= ($this->aggressive ? 7 : 30) && !$this->isInUse($file)) {
                $this->plan[] = ['category' => 'logs', 'path' => $file, 'bytes' => (int) filesize($file), 'age_days' => round($age, 1), 'action' => 'rm'];
            }
        }
    }

    /** Package caches: only when they pass a size threshold (they refill on demand). */
    private function planCaches(): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $caches = [
            // path => [threshold bytes, aggressive threshold]
            $home . '/.npm/_cacache' => [3e9, 5e8],
            $home . '/.npm/_npx' => [1e9, 2e8],
            $home . '/.cache/pip' => [1e9, 2e8],
            $home . '/.cache/pip-tools' => [5e8, 1e8],
            $home . '/.cache/uv' => [2e9, 5e8],
            $home . '/.cache/go-build' => [2e9, 5e8],
            $home . '/.gradle/caches/build-cache-1' => [1e9, 2e8],
        ];
        foreach ($caches as $path => [$normal, $aggressive]) {
            if (!is_dir($path) || $this->isInUse($path)) {
                continue;
            }
            $bytes = $this->du($path);
            if ($bytes >= ($this->aggressive ? $aggressive : $normal)) {
                $this->plan[] = ['category' => 'caches', 'path' => $path, 'bytes' => $bytes, 'age_days' => null, 'action' => 'empty'];
            }
        }
    }

    /**
     * Stopped containers (exited/created) older than 7 days (aggressive: 1) that are not
     * PocketDev's own, then images no container uses anymore (older than 7 days).
     */
    private function planDocker(): void
    {
        $minAgeHours = $this->aggressive ? 24 : 168;
        $rows = (string) shell_exec("docker ps -a --filter status=exited --filter status=created --format '{{.ID}}\t{{.Names}}\t{{.Image}}\t{{.CreatedAt}}' 2>/dev/null");
        foreach (array_filter(explode("\n", $rows)) as $row) {
            [$id, $name, $image, $created] = array_pad(explode("\t", $row), 4, '');
            if (str_starts_with($name, 'pocket-dev') || $id === '') {
                continue;
            }
            $finished = trim((string) shell_exec('docker inspect -f ' . escapeshellarg('{{.State.FinishedAt}}') . ' ' . escapeshellarg($id) . ' 2>/dev/null'));
            $since = str_starts_with($finished, '0001') ? strtotime(substr($created, 0, 19)) : strtotime($finished);
            $ageHours = $since ? (time() - $since) / 3600 : 0;
            if ($ageHours < $minAgeHours) {
                continue;
            }
            $imageSize = (int) trim((string) shell_exec('docker image inspect -f ' . escapeshellarg('{{.Size}}') . ' ' . escapeshellarg($image) . ' 2>/dev/null'));
            $this->plan[] = ['category' => 'docker', 'path' => 'container ' . $name . ' (' . $image . ')', 'bytes' => $imageSize, 'age_days' => round($ageHours / 24, 1), 'action' => 'docker-rm:' . $id];
        }
        // Dangling + unused images older than 7 days (after the containers above are gone).
        // Docker's own size accounting is unreliable with the containerd snapshotter, so 0 is planned.
        $this->plan[] = ['category' => 'docker', 'path' => 'ongebruikte images ouder dan ' . ($this->aggressive ? '1 dag' : '7 dagen'), 'bytes' => 0, 'age_days' => null, 'action' => 'docker-image-prune:' . ($this->aggressive ? '24h' : '168h')];
    }

    private function planOldFiles(string $category, string $dir, int $days): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $cmd = 'find ' . escapeshellarg($dir) . ' -xdev -type f -mtime +' . (int) $days . ' -printf ' . escapeshellarg('%p\t%s\t%T@\n') . ' 2>/dev/null';
        $count = 0;
        $bytes = 0;
        $oldest = time();
        foreach (array_filter(explode("\n", (string) shell_exec($cmd))) as $line) {
            [$path, $size, $mtime] = array_pad(explode("\t", $line), 3, 0);
            if ($this->isInUse($path)) {
                continue;
            }
            $count++;
            $bytes += (int) $size;
            $oldest = min($oldest, (int) $mtime);
        }
        if ($count > 0) {
            $this->plan[] = ['category' => $category, 'path' => $dir . " ({$count} bestanden ouder dan {$days} d)", 'bytes' => $bytes, 'age_days' => round((time() - $oldest) / 86400, 1), 'action' => 'find-delete:' . $dir . ':' . $days];
        }
    }

    // ------------------------------------------------------------------ execution

    private function execute(array $item): array
    {
        $action = $item['action'];
        $out = '';
        $ok = true;

        try {
            if ($action === 'rm') {
                // Re-check right before deleting: a process may have started using it since planning
                if ($this->isInUse($item['path'], fresh: true)) {
                    return $item + ['done' => false, 'error' => 'in gebruik geraakt'];
                }
                $ok = File::isDirectory($item['path']) ? File::deleteDirectory($item['path']) : File::delete($item['path']);
                if (!$ok && file_exists($item['path'])) {
                    exec('rm -rf -- ' . escapeshellarg($item['path']) . ' 2>&1', $lines, $code);
                    $ok = $code === 0;
                    $out = implode("\n", array_slice($lines, 0, 3));
                }
            } elseif ($action === 'empty') {
                if ($this->isInUse($item['path'], fresh: true)) {
                    return $item + ['done' => false, 'error' => 'in gebruik geraakt'];
                }
                exec('find ' . escapeshellarg($item['path']) . ' -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>&1', $lines, $code);
                $ok = $code === 0;
                $out = implode("\n", array_slice($lines, 0, 3));
            } elseif (str_starts_with($action, 'find-delete:')) {
                [, $dir, $days] = explode(':', $action);
                exec('find ' . escapeshellarg($dir) . ' -xdev -type f -mtime +' . (int) $days . ' -delete 2>&1; find ' . escapeshellarg($dir) . ' -xdev -mindepth 1 -type d -empty -delete 2>&1', $lines, $code);
                $ok = $code === 0;
                $out = implode("\n", array_slice($lines, 0, 3));
            } elseif (str_starts_with($action, 'docker-rm:')) {
                exec('docker rm ' . escapeshellarg(substr($action, 10)) . ' 2>&1', $lines, $code);
                $ok = $code === 0;
                $out = implode("\n", $lines);
            } elseif (str_starts_with($action, 'docker-image-prune:')) {
                exec('docker image prune -a -f --filter ' . escapeshellarg('until=' . substr($action, 19)) . ' 2>&1', $lines, $code);
                $ok = $code === 0;
                $out = (string) end($lines);
            }
        } catch (\Throwable $e) {
            $ok = false;
            $out = $e->getMessage();
        }

        return $item + ['done' => $ok] + ($out !== '' ? ['error' => $ok ? null : $out, 'output' => $out] : []);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Every path a process in this container uses: cwd, executable and open files.
     *
     * @return array<int, string>
     */
    private function pathsInUse(): array
    {
        $paths = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
            foreach (['cwd', 'exe'] as $link) {
                $target = @readlink($proc . '/' . $link);
                if ($target) {
                    $paths[] = preg_replace('/ \(deleted\)$/', '', $target);
                }
            }
            foreach (glob($proc . '/fd/*') ?: [] as $fd) {
                $target = @readlink($fd);
                if ($target && $target[0] === '/') {
                    $paths[] = preg_replace('/ \(deleted\)$/', '', $target);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function isInUse(string $path, bool $fresh = false): bool
    {
        if ($fresh) {
            $this->inUse = $this->pathsInUse();
        }
        $prefix = rtrim($path, '/') . '/';
        foreach ($this->inUse as $used) {
            if ($used === $path || str_starts_with($used, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function du(string $path): int
    {
        return (int) trim((string) shell_exec('du -sxb ' . escapeshellarg($path) . ' 2>/dev/null | cut -f1'));
    }

    private function newestMtime(string $dir, string $exclude): ?int
    {
        $cmd = 'find ' . escapeshellarg($dir) . ' -xdev -path ' . escapeshellarg($exclude) . ' -prune -o -path ' . escapeshellarg($dir . '/.git') . ' -prune -o -type f -printf ' . escapeshellarg('%T@\n') . ' 2>/dev/null | sort -nr 2>/dev/null | head -1';
        $out = trim((string) shell_exec($cmd));

        return $out !== '' ? (int) $out : null;
    }

    private static function supervisorSocket(): ?string
    {
        foreach (['/tmp/supervisor.sock', '/var/run/supervisor.sock', '/run/supervisor.sock'] as $sock) {
            if (file_exists($sock)) {
                return $sock;
            }
        }

        return null;
    }
}
