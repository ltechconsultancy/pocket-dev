<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * "This conversation is being processed by a job right now."
 *
 * Two jobs (or a job + Remote Control) running Claude on the same session at
 * once kill each other (SIGTERM, exit 143) and turns end silently. The lock is
 * a Redis key with a short TTL, refreshed on every stream event, so a crashed
 * worker or container restart frees the chat within minutes instead of hours.
 *
 * Value: "{token}|{hostname}|{pid}" so a lock held by a dead worker process in
 * this container is detected and taken over immediately.
 */
class ConversationRunLock
{
    // Long enough for silent tool runs; a dead worker in this container is detected at once
    public const TTL_SECONDS = 900;

    public static function key(string $conversationUuid): string
    {
        return "conversation-running:{$conversationUuid}";
    }

    /**
     * @return string|null Token when acquired, null when another live job holds it
     */
    public static function acquire(string $conversationUuid): ?string
    {
        $key = self::key($conversationUuid);
        $token = (string) Str::uuid();
        $value = $token . '|' . gethostname() . '|' . getmypid();

        if (Redis::set($key, $value, 'EX', self::TTL_SECONDS, 'NX')) {
            return $token;
        }

        // Held by a worker process that no longer exists (killed/restarted)?
        $current = (string) Redis::get($key);
        if ($current !== '' && self::ownerIsDead($current)) {
            Redis::del($key);
            if (Redis::set($key, $value, 'EX', self::TTL_SECONDS, 'NX')) {
                return $token;
            }
        }

        return null;
    }

    public static function release(string $conversationUuid, string $token): void
    {
        $key = self::key($conversationUuid);
        $current = (string) Redis::get($key);
        if (str_starts_with($current, $token . '|')) {
            Redis::del($key);
        }
    }

    public static function forceRelease(string $conversationUuid): void
    {
        Redis::del(self::key($conversationUuid));
    }

    public static function isHeld(string $conversationUuid): bool
    {
        $current = (string) Redis::get(self::key($conversationUuid));

        return $current !== '' && !self::ownerIsDead($current);
    }

    /**
     * Keep the lock alive while the job produces events (called per event).
     */
    public static function refresh(string $conversationUuid): void
    {
        Redis::expire(self::key($conversationUuid), self::TTL_SECONDS);
    }

    private static function ownerIsDead(string $value): bool
    {
        $parts = explode('|', $value);
        if (count($parts) !== 3) {
            return false;
        }
        [, $host, $pid] = $parts;

        // Only checkable for workers in this container (same pid namespace)
        if ($host !== gethostname() || !ctype_digit($pid)) {
            return false;
        }

        return !file_exists("/proc/{$pid}");
    }
}
