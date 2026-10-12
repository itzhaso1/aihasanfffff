<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Core\Data\ModelFinishReason;
use App\AI\Models\Mapping\OpenAiResponseNormalizer;
use Tests\TestCase;

class OpenAiResponseNormalizerTest extends TestCase
{
    public function test_visible_text_excludes_reasoning_parts(): void
    {
        $response = (new OpenAiResponseNormalizer)->normalize([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => [
                        ['type' => 'reasoning', 'text' => 'Since the user asked about stock.'],
                        ['type' => 'text', 'text' => 'المنتج متوفر'],
                    ],
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 4,
                'completion_tokens' => 6,
            ],
        ], 'gpt-4o-mini');

        $this->assertSame('المنتج متوفر', $response->text);
        $this->assertSame(ModelFinishReason::Stop, $response->finishReason);
        $this->assertStringNotContainsString('Since the user asked', (string) $response->text);
    }

    public function test_string_content_stays_the_customer_reply(): void
    {
        $response = (new OpenAiResponseNormalizer)->normalize([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => 'أهلا، كيف أقدر أساعدك؟',
                ],
            ]],
        ], 'gpt-4o-mini');

        $this->assertSame('أهلا، كيف أقدر أساعدك؟', $response->text);
    }
}
