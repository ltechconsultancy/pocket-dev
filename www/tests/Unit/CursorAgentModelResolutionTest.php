<?php

namespace Tests\Unit;

use App\Services\Providers\CursorAgentProvider;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

class CursorAgentModelResolutionTest extends TestCase
{
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = app(CursorAgentProvider::class);
        Cache::forget('cursor_agent:all_model_ids');
    }

    /**
     * @dataProvider modelResolutionProvider
     */
    public function test_resolve_model_id(
        string $base,
        string $effort,
        bool $thinking,
        array $modelConfig,
        string $expected
    ): void {
        Cache::put('cursor_agent:all_model_ids', [
            'auto' => true,
            'claude-opus-4-7' => true,
            'claude-opus-4-7-max' => true,
            'claude-opus-4-7-thinking-high' => true,
            'claude-opus-4-7-thinking-max' => true,
            'claude-4.6-opus-high-thinking' => true,
            'claude-4.6-opus-high' => true,
            'claude-4.5-sonnet-thinking' => true,
            'claude-4.5-sonnet' => true,
            'gpt-5.5-high' => true,
            'gpt-5.5' => true,
        ], 60);

        $resolved = $this->invokeResolveModelId($base, $effort, $thinking, $modelConfig);

        $this->assertSame($expected, $resolved);
    }

    public static function modelResolutionProvider(): array
    {
        $opus47 = [
            'effort_variants' => [
                'type' => 'prefix_thinking',
                'has_thinking' => true,
                'levels' => ['low', 'medium', 'high', 'xhigh', 'max'],
                'default' => 'high',
            ],
        ];

        $opus46 = [
            'effort_variants' => [
                'type' => 'suffix_thinking',
                'has_thinking' => true,
                'levels' => ['high', 'max'],
                'default' => 'high',
            ],
        ];

        $sonnet45 = [
            'effort_variants' => [
                'type' => 'toggle_thinking',
                'has_thinking' => true,
                'levels' => ['medium'],
                'default' => 'medium',
            ],
        ];

        $gpt55 = [
            'effort_variants' => [
                'type' => 'suffix',
                'has_thinking' => false,
                'levels' => ['low', 'medium', 'high'],
                'default' => 'high',
            ],
        ];

        return [
            'auto unchanged' => ['auto', 'high', true, ['effort_variants' => null], 'auto'],
            'opus 4.7 thinking high' => ['claude-opus-4-7', 'high', true, $opus47, 'claude-opus-4-7-thinking-high'],
            'opus 4.7 no thinking max' => ['claude-opus-4-7', 'max', false, $opus47, 'claude-opus-4-7-max'],
            'opus 4.6 thinking high' => ['claude-4.6-opus', 'high', true, $opus46, 'claude-4.6-opus-high-thinking'],
            'opus 4.6 no thinking high' => ['claude-4.6-opus', 'high', false, $opus46, 'claude-4.6-opus-high'],
            'sonnet thinking on' => ['claude-4.5-sonnet', 'medium', true, $sonnet45, 'claude-4.5-sonnet-thinking'],
            'sonnet thinking off' => ['claude-4.5-sonnet', 'medium', false, $sonnet45, 'claude-4.5-sonnet'],
            'gpt suffix effort' => ['gpt-5.5', 'high', false, $gpt55, 'gpt-5.5-high'],
            'none maps to default effort' => ['claude-opus-4-7', 'none', true, $opus47, 'claude-opus-4-7-thinking-high'],
        ];
    }

    public function test_clamps_unknown_resolved_id_to_base(): void
    {
        Cache::put('cursor_agent:all_model_ids', [
            'claude-opus-4-7' => true,
            'claude-opus-4-7-thinking-high' => true,
        ], 60);

        $clamped = $this->invokeClampToKnownModelId('claude-opus-4-7-max', 'claude-opus-4-7');

        $this->assertSame('claude-opus-4-7', $clamped);
    }

    private function invokeResolveModelId(
        string $base,
        string $effort,
        bool $thinking,
        array $modelConfig
    ): string {
        $ref = new ReflectionClass(CursorAgentProvider::class);
        $method = $ref->getMethod('resolveModelId');
        $method->setAccessible(true);

        return $method->invoke($this->provider, $base, $effort, $thinking, $modelConfig);
    }

    private function invokeClampToKnownModelId(string $resolved, string $baseModel): string
    {
        $ref = new ReflectionClass(CursorAgentProvider::class);
        $method = $ref->getMethod('clampToKnownModelId');
        $method->setAccessible(true);

        return $method->invoke($this->provider, $resolved, $baseModel);
    }
}
