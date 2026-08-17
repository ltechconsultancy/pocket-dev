<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis FIFO of follow-up prompts queued while a Cursor Agent stream is running.
 *
 * Stored as stream:{uuid}:followups — a list of JSON objects.
 */
class CursorFollowUpQueue
{
    private const KEY_SUFFIX = ':followups';
    private const TTL_SECONDS = 3600;
    private const MAX_ITEMS = 20;

    /**
     * @return array{id: string, prompt: string, queued_at: string}
     */
    public function enqueue(string $conversationUuid, string $prompt): array
    {
        $item = [
            'id' => (string) Str::ulid(),
            'prompt' => $prompt,
            'queued_at' => now()->format('Y-m-d H:i:s'),
        ];

        $key = $this->key($conversationUuid);
        Redis::rpush($key, json_encode($item));
        Redis::ltrim($key, -self::MAX_ITEMS, -1);
        Redis::expire($key, self::TTL_SECONDS);

        Log::channel('api')->info('CursorFollowUpQueue: enqueued', [
            'conversation' => $conversationUuid,
            'id' => $item['id'],
            'queued_at' => $item['queued_at'],
            'prompt' => $prompt,
        ]);

        return $item;
    }

    /**
     * @return array<int, array{id: string, prompt: string, queued_at: string}>
     */
    public function list(string $conversationUuid): array
    {
        $raw = Redis::lrange($this->key($conversationUuid), 0, -1) ?: [];

        return array_values(array_filter(array_map(function ($json) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        }, $raw)));
    }

    public function hasItems(string $conversationUuid): bool
    {
        return (int) Redis::llen($this->key($conversationUuid)) > 0;
    }

    /**
     * Atomically read and clear the queue.
     *
     * @return array<int, array{id: string, prompt: string, queued_at: string}>
     */
    public function drain(string $conversationUuid): array
    {
        $key = $this->key($conversationUuid);
        $items = $this->list($conversationUuid);
        Redis::del($key);

        return $items;
    }

    public function remove(string $conversationUuid, string $id): bool
    {
        $items = $this->list($conversationUuid);
        $kept = array_values(array_filter($items, fn ($item) => ($item['id'] ?? '') !== $id));
        if (count($kept) === count($items)) {
            return false;
        }

        $key = $this->key($conversationUuid);
        Redis::del($key);
        foreach ($kept as $item) {
            Redis::rpush($key, json_encode($item));
        }
        if ($kept !== []) {
            Redis::expire($key, self::TTL_SECONDS);
        }

        return true;
    }

    public function clear(string $conversationUuid): void
    {
        Redis::del($this->key($conversationUuid));
    }

    private function key(string $conversationUuid): string
    {
        return 'stream:' . $conversationUuid . self::KEY_SUFFIX;
    }
}
