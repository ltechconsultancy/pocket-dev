<?php

namespace App\Services;

/**
 * Formats queued Cursor follow-up prompts into a single user message.
 */
class CursorFollowUpPrompt
{
    public static function supports(string $providerType): bool
    {
        return in_array($providerType, ['cursor_agent', 'claude_code', 'opencode'], true);
    }

    /**
     * Whether a queued follow-up may interrupt the running turn (once no tool is in flight).
     * OpenCode reports a tool only after it finished, so a running command can't be seen:
     * its follow-ups run right after the current answer instead.
     */
    public static function interruptsRun(string $providerType): bool
    {
        return in_array($providerType, ['cursor_agent', 'claude_code'], true);
    }

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
