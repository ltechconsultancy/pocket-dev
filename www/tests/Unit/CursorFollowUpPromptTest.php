<?php

namespace Tests\Unit;

use App\Services\CursorFollowUpPrompt;
use Tests\TestCase;

class CursorFollowUpPromptTest extends TestCase
{
    public function test_formats_single_queued_prompt(): void
    {
        $formatted = CursorFollowUpPrompt::format([
            ['prompt' => 'Fix the tests', 'queued_at' => '2026-08-13 15:51:02'],
        ]);

        $this->assertSame(
            'This prompt was added mid stream: 2026-08-13 15:51:02: Fix the tests',
            $formatted
        );
    }

    public function test_formats_multiple_queued_prompts(): void
    {
        $formatted = CursorFollowUpPrompt::format([
            ['prompt' => 'Fix the tests', 'queued_at' => '2026-08-13 15:51:02'],
            ['prompt' => 'Then run pint', 'queued_at' => '2026-08-13 15:51:15'],
        ]);

        $this->assertSame(
            'This prompt was added mid stream: 2026-08-13 15:51:02: Fix the tests. 2026-08-13 15:51:15: Then run pint',
            $formatted
        );
    }

    public function test_skips_empty_prompts(): void
    {
        $formatted = CursorFollowUpPrompt::format([
            ['prompt' => '  ', 'queued_at' => '2026-08-13 15:51:02'],
            ['prompt' => 'Keep this', 'queued_at' => '2026-08-13 15:51:15'],
        ]);

        $this->assertSame(
            'This prompt was added mid stream: 2026-08-13 15:51:15: Keep this',
            $formatted
        );
    }
}
