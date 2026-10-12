<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Prompt\PromptComposition;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Models\Adapters\GeminiAdapter;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderId;
use App\AI\Models\ResolvedModelSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiRateLimitTest extends TestCase
{
    public function test_429_is_classified_as_rate_limited_after_the_existing_two_attempts(): void
    {
        config([
            'services.google_ai_studio.key' => 'test-key-not-a-secret',
            'ai.google_ai_studio.base_url' => 'https://example.test',
        ]);
        Http::fake(['*' => Http::response(['error' => 'slow down'], 429)]);

        try {
            (new GeminiAdapter)->generate(
                new ResolvedModelSettings(2, 2, ProviderId::GEMINI, 'gemini-3.6-flash', 1, 256, 0),
                new ModelRequest(2, AiRunMode::Preview, new PromptComposition([
                    PromptSection::userMessage('هلا'),
                ])),
            );
            $this->fail('A 429 must not become a successful model response.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::RATE_LIMITED, $exception->errorCode);
            $this->assertSame(429, $exception->httpStatus);
        }

        $this->assertCount(2, Http::recorded());
    }
}
