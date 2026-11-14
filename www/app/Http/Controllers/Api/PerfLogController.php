<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Client-side load timings (one JSON line per page load), so slow devices
 * (phone / installed PWA) can be compared with the laptop on real numbers.
 *
 * Written by the beacon at the bottom of chat.blade.php to storage/logs/perf.log.
 * Read with: tail storage/logs/perf.log | jq
 */
class PerfLogController extends Controller
{
    private const MAX_LOG_BYTES = 2_000_000;

    public function store(Request $request): Response
    {
        $data = json_decode((string) $request->input('data', ''), true);
        if (! is_array($data)) {
            return response()->noContent(422);
        }

        // Only flat scalar values with short keys; drop anything else
        $clean = [];
        foreach (array_slice($data, 0, 40, true) as $key => $value) {
            if (! is_string($key) || strlen($key) > 40) {
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 200);
            }
        }

        $path = storage_path('logs/perf.log');
        if (is_file($path) && filesize($path) > self::MAX_LOG_BYTES) {
            @rename($path, $path.'.1');
        }

        $line = json_encode(['at' => now()->toIso8601String()] + $clean, JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);

        return response()->noContent();
    }
}
