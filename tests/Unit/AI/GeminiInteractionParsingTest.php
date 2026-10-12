<?php

namespace Tests\Unit\AI;

use App\Services\AI\Gemini\GeminiInteraction;
use Tests\TestCase;

class GeminiInteractionParsingTest extends TestCase
{
    public function test_parses_model_output_steps_and_usage_aliases(): void
    {
        $interaction = GeminiInteraction::fromResponse([
            'id' => 'int_live',
            'status' => 'completed',
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => [
                        ['type' => 'text', 'text' => 'المبيعات 5000.00 ريال.'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 11,
                'output_tokens' => 7,
            ],
        ]);

        $this->assertSame('int_live', $interaction->id);
        $this->assertSame('المبيعات 5000.00 ريال.', $interaction->outputText);
        $this->assertSame(11, $interaction->usage->inputTokens);
        $this->assertSame(7, $interaction->usage->outputTokens);
        $this->assertSame(18, $interaction->usage->totalTokens);
    }

    public function test_thought_steps_are_not_included_in_output_text(): void
    {
        $interaction = GeminiInteraction::fromResponse([
            'id' => 'int_thought',
            'status' => 'completed',
            'steps' => [
                [
                    'type' => 'thought',
                    'text' => 'Since the user asked about sales.',
                ],
                [
                    'type' => 'model_output',
                    'content' => [
                        ['thought' => true, 'text' => 'Need the daily total.'],
                        ['type' => 'text', 'text' => 'مبيعات اليوم 5000.00 ريال.'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('مبيعات اليوم 5000.00 ريال.', $interaction->outputText);
        $this->assertStringNotContainsString('Since the user asked', $interaction->outputText);
        $this->assertStringNotContainsString('Need the daily total.', $interaction->outputText);
    }
}
