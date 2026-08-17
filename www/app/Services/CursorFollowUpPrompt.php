<?php

namespace App\Services;

/**
 * Formats queued Cursor follow-up prompts into a single user message.
 */
class CursorFollowUpPrompt
{
    /**
     * @param  array<int, array{prompt: string, queued_at: string}>  $items
     */
    public static function format(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $timestamp = trim((string) ($item['queued_at'] ?? ''));
            $prompt = trim((string) ($item['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $parts[] = ($timestamp !== '' ? $timestamp . ': ' : '') . $prompt;
        }

        return 'This prompt was added mid stream: ' . implode('. ', $parts);
    }
}
