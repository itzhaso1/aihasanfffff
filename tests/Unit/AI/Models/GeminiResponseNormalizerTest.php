<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Core\Data\ModelFinishReason;
use App\AI\Models\Mapping\GeminiResponseNormalizer;
use Tests\TestCase;

class GeminiResponseNormalizerTest extends TestCase
{
    public function test_visible_text_excludes_thought_parts(): void
    {
        $response = (new GeminiResponseNormalizer)->normalize([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [
                        ['thought' => true, 'text' => 'Since the user asked about availability.'],
                        ['text' => 'المنتج متوفر عندنا'],
                    ],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 8,
                'candidatesTokenCount' => 12,
            ],
        ], 'gemini-2.5-flash');

        $this->assertSame('المنتج متوفر عندنا', $response->text);
        $this->assertSame(ModelFinishReason::Stop, $response->finishReason);
        $this->assertStringNotContainsString('Since the user asked', (string) $response->text);
    }

    public function test_thought_only_candidate_has_no_customer_text(): void
    {
        $response = (new GeminiResponseNormalizer)->normalize([
            'candidates' => [[
                'finishReason' => 'MAX_TOKENS',
                'content' => [
                    'parts' => [
                        ['thought' => true, 'text' => 'Since the user asked'],
                    ],
                ],
            ]],
        ], 'gemini-2.5-flash');

        $this->assertNull($response->text);
        $this->assertSame(ModelFinishReason::Length, $response->finishReason);
    }

    public function test_function_call_text_is_not_treated_as_the_reply(): void
    {
        $response = (new GeminiResponseNormalizer)->normalize([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [
                        [
                            'text' => '{"name":"search_products"}',
                            'functionCall' => [
                                'id' => 'call-1',
                                'name' => 'search_products',
                                'args' => ['query' => 'حذاء'],
                            ],
                        ],
                        ['text' => 'لقيت المنتج'],
                    ],
                ],
            ]],
        ], 'gemini-2.5-flash');

        $this->assertSame('لقيت المنتج', $response->text);
        $this->assertSame('search_products', $response->toolCalls[0]->name);
        $this->assertStringNotContainsString('search_products', (string) $response->text);
    }
}
