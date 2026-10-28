<?php

namespace App\Support;

use RuntimeException;

/**
 * Read/write/delete files in appuser's home (e.g. ~/.claude/.credentials.json).
 *
 * The CLIs (Claude Code, Codex, ...) run as appuser and keep their credential
 * files at mode 0600; every login or token refresh resets that. PHP-FPM runs as
 * www-data and can't read or write them, so a chmod in the web request only
 * worked until the next refresh ("Failed to save credentials: Permission denied").
 *
 * As appuser (queue container) this works directly; from php-fpm it goes through
 * `docker exec -u appuser <queue>` with the content on stdin (never on the command
 * line, so secrets don't show up in process lists or logs). Writes are atomic
 * (temp file + rename) and keep the CLI's expected owner and mode.
 */
class AppUserFiles
{
    public static function read(string $path): ?string
    {
        if (self::isAppUser()) {
            return is_file($path) ? (string) file_get_contents($path) : null;
        }

        [$code, $out] = self::exec(['sh', '-c', 'test -f "$1" && cat "$1"', 'sh', $path]);

        return $code === 0 ? $out : null;
    }

    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    public static function write(string $path, string $content, int $mode = 0600): void
    {
        if (self::isAppUser()) {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create {$dir}");
            }
            $tmp = tempnam($dir, basename($path) . '.');
            if ($tmp === false || file_put_contents($tmp, $content) === false) {
                throw new RuntimeException("Cannot write {$path}");
            }
            chmod($tmp, $mode);
            if (!rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException("Cannot replace {$path}");
            }

            return;
        }

        $script = 'set -e; mkdir -p "$(dirname "$1")"; t=$(mktemp "$1.XXXXXX"); cat > "$t"; chmod "$2" "$t"; mv -f "$t" "$1"';
        [$code, $out, $err] = self::exec(['sh', '-c', $script, 'sh', $path, sprintf('%o', $mode)], $content);
        if ($code !== 0) {
            throw new RuntimeException(trim($err) !== '' ? trim($err) : "Cannot write {$path} as appuser");
        }
    }

    public static function delete(string $path): void
    {
        if (self::isAppUser()) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        [$code, , $err] = self::exec(['rm', '-f', '--', $path]);
        if ($code !== 0) {
            throw new RuntimeException(trim($err) !== '' ? trim($err) : "Cannot delete {$path} as appuser");
        }
    }

    private static function isAppUser(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';

        return function_exists('posix_geteuid') && @fileowner($home) === posix_geteuid();
    }

    /**
     * Run a command as appuser in the queue container.
     *
     * @param  array<int, string>  $argv
     * @return array{0: int, 1: string, 2: string} [exit code, stdout, stderr]
     */
    private static function exec(array $argv, ?string $stdin = null): array
    {
        $container = config('pocketdev.project_name', 'pocket-dev') . '-queue';
        $command = array_merge(['docker', 'exec', '-i', '-u', 'appuser', '-e', 'HOME=/home/appuser', $container], $argv);

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start docker exec');
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
