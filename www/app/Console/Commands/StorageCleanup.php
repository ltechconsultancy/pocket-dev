<?php

namespace App\Console\Commands;

use App\Services\StorageJanitor;
use Illuminate\Console\Command;

/**
 * Remove what fills the disk: old /tmp work dirs (never chat uploads), old PocketDev logs,
 * oversized package caches and (with --docker) stopped containers + unused images.
 * Never touches anything a process uses, and never deletes project dependencies
 * (--report-deps only lists them). Always try --dry-run first.
 */
class StorageCleanup extends Command
{
    protected $signature = 'storage:cleanup
        {--dry-run : Only show what would be removed}
        {--aggressive : Shorter age windows and lower cache thresholds}
        {--docker : Also remove stopped containers (not pocket-dev-*) and unused images}
        {--only= : Comma-separated parts: tmp,logs,caches,docker}
        {--report-deps : List project dependency folders (node_modules, .venv) untouched for 30 days; never deletes}
        {--json : Output JSON}';

    protected $description = 'Free disk space safely (tmp, logs, caches, optionally docker; never chat uploads)';

    public function handle(): int
    {
        $janitor = new StorageJanitor((bool) $this->option('aggressive'));

        if ($this->option('report-deps')) {
            $deps = $janitor->reportProjectDependencies();
            if ($this->option('json')) {
                $this->line(json_encode($deps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $this->table(['Grootte', 'Project ouder (d)', 'In gebruik', 'Pad'], array_map(fn ($d) => [
                self::size($d['bytes']), $d['project_age_days'] ?? '?', $d['in_use'] ? 'JA (overslaan)' : '', $d['path'],
            ], $deps));
            $this->line('Totaal: ' . self::size(array_sum(array_column($deps, 'bytes'))) . ' — alleen rapportage, er is niets verwijderd.');

            return self::SUCCESS;
        }

        $parts = $this->option('only')
            ? array_map('trim', explode(',', (string) $this->option('only')))
            : array_merge(['tmp', 'logs', 'caches'], $this->option('docker') ? ['docker'] : []);

        $report = $janitor->run((bool) $this->option('dry-run'), $parts);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $byCategory = [];
        foreach ($report['items'] as $item) {
            $byCategory[$item['category']][] = $item;
        }
        foreach ($byCategory as $category => $items) {
            usort($items, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
            $this->info(strtoupper($category) . ' — ' . count($items) . ' items, ' . self::size(array_sum(array_column($items, 'bytes'))));
            foreach (array_slice($items, 0, 25) as $item) {
                $status = $report['dry_run'] ? '' : (($item['done'] ?? false) ? ' ✓' : ' ✗ ' . ($item['error'] ?? ''));
                $this->line(sprintf('  %9s  %6s  %s%s', self::size($item['bytes']), $item['age_days'] !== null ? $item['age_days'] . 'd' : '', $item['path'], $status));
            }
            if (count($items) > 25) {
                $this->line('  … en ' . (count($items) - 25) . ' meer');
            }
        }

        $this->newLine();
        $this->line(($report['dry_run'] ? 'DRY-RUN — gepland: ' : 'Gepland: ') . self::size($report['planned_bytes'])
            . ($report['dry_run'] ? '' : ' — vrijgekomen volgens df: ' . self::size($report['freed_bytes']))
            . ' — schijf ' . $report['disk_after']['used_percent'] . '% vol');

        return self::SUCCESS;
    }

    public static function size(int|float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if (abs($bytes) < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
