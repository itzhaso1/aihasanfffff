<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ToolExchange;
use App\AI\Core\Data\ToolSpec;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\PromptComposer;
use App\AI\Models\Adapters\GeminiAdapter;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderId;
use App\AI\Models\ResolvedModelSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_ai_studio.key' => 'test-gemini-key',
            'ai.google_ai_studio.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);
    }

    public function test_generate_maps_tools_thought_signatures_and_usage(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [[
                            'thoughtSignature' => 'sig-abc',
                            'functionCall' => [
                                'id' => 'provider-call-1',
                                'name' => 'search_products',
                                'args' => ['query' => 'فستان'],
                            ],
                        ]],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 11,
                    'candidatesTokenCount' => 7,
                ],
            ]);
        });

        $response = (new GeminiAdapter)->generate($this->settings(), $this->request());

        $payload = $captured->data();
        $this->assertSame('test-gemini-key', $captured->header('x-goog-api-key')[0] ?? $captured->header('X-Goog-Api-Key')[0] ?? null);
        $this->assertStringContainsString('Be truthful.', $payload['system_instruction']['parts'][0]['text']);
        $this->assertSame('OBJECT', $payload['tools'][0]['functionDeclarations'][0]['parameters']['type']);
        $this->assertSame(128, $payload['generationConfig']['thinkingConfig']['thinkingBudget']);
        $this->assertFalse($payload['generationConfig']['thinkingConfig']['includeThoughts']);
        $this->assertSame('sig-abc', $payload['contents'][1]['parts'][0]['thoughtSignature']);
        $this->assertSame('provider-call-1', $payload['contents'][1]['parts'][0]['functionCall']['id']);
        $this->assertTrue($payload['contents'][2]['parts'][0]['functionResponse']['response']['success']);

        $this->assertSame(ModelFinishReason::ToolCalls, $response->finishReason);
        $this->assertSame('search_products', $response->toolCalls[0]->name);
        $this->assertSame('provider-call-1', $response->toolCalls[0]->callId);
        $this->assertSame('sig-abc', $response->toolCalls[0]->providerMetadata['thought_signature']);
        $this->assertSame(11, $response->usage?->inputTokens);
        $this->assertSame(7, $response->usage?->outputTokens);
        $this->assertSame(ProviderId::GEMINI, $response->usage?->provider);
    }

    public function test_safety_block_becomes_a_safety_finish_without_text(): void
    {
        Http::fake([
            '*' => Http::response([
                'promptFeedback' => ['blockReason' => 'SAFETY'],
                'candidates' => [],
            ]),
        ]);

        $response = (new GeminiAdapter)->generate(
            $this->settings(),
            new ModelRequest(3, AiRunMode::Preview, (new PromptComposer)->compose(
                [], [], [], [], [], PromptSection::userMessage('هلا'),
            )),
        );

        $this->assertSame(ModelFinishReason::Safety, $response->finishReason);
        $this->assertNull($response->text);
        $this->assertSame([], $response->toolCalls);
    }

    public function test_generate_keeps_thought_summaries_out_of_visible_text(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [
                            ['thought' => true, 'text' => 'Since the user asked for a greeting.'],
                            ['text' => 'هلا، تفضل'],
                        ],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 4,
                ],
            ]),
        ]);

        $response = (new GeminiAdapter)->generate($this->settings(), $this->plainRequest());

        $this->assertSame('هلا، تفضل', $response->text);
        $this->assertStringNotContainsString('Since the user asked', (string) $response->text);
        $this->assertSame(ModelFinishReason::Stop, $response->finishReason);
    }

    public function test_missing_api_key_and_http_errors_are_normalized(): void
    {
        config(['services.google_ai_studio.key' => '']);
        try {
            (new GeminiAdapter)->generate($this->settings(), $this->plainRequest());
            $this->fail('Missing key was accepted.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::MISSING_API_KEY, $exception->errorCode);
        }

        config(['services.google_ai_studio.key' => 'test-gemini-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 500)]);

        try {
            (new GeminiAdapter)->generate($this->settings(), $this->plainRequest());
            $this->fail('HTTP error was accepted.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::HTTP_ERROR, $exception->errorCode);
            $this->assertSame(500, $exception->httpStatus);
        }
    }

    private function settings(): ResolvedModelSettings
    {
        return new ResolvedModelSettings(3, 9, ProviderId::GEMINI, 'gemini-2.5-flash', 0.4, 256, 128);
    }

    private function request(): ModelRequest
    {
        return new ModelRequest(
            employeeId: 3,
            runMode: AiRunMode::Preview,
            prompt: (new PromptComposer)->compose(
                [new PromptSection(PromptLayer::Constitution, 'constitution.core', 'Be truthful.')],
                [],
                [],
                [],
                [],
                PromptSection::userMessage('فستان'),
            ),
            tools: [new ToolSpec('search_products', 'Search catalog products.', [
                'query' => ['type' => 'string', 'required' => true],
            ])],
            toolExchanges: [new ToolExchange(
                new ModelToolCall('provider-call-1', 'search_products', ['query' => 'فستان'], ['thought_signature' => 'sig-abc']),
                ['count' => 1],
            )],
        );
    }

    private function plainRequest(): ModelRequest
    {
        return new ModelRequest(3, AiRunMode::Preview, (new PromptComposer)->compose(
            [], [], [], [], [], PromptSection::userMessage('هلا'),
        ));
    }
}
