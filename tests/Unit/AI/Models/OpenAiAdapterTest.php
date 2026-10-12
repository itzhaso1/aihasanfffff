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
use App\AI\Models\Adapters\OpenAiAdapter;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ProviderId;
use App\AI\Models\ResolvedModelSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'test-openai-key']);
    }

    public function test_generate_sends_native_tools_and_normalizes_tool_calls(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response([
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_products',
                                'arguments' => '{"query":"فستان"}',
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8],
            ]);
        });

        $response = (new OpenAiAdapter)->generate($this->settings(), $this->request());
        $payload = $captured->data();

        $this->assertSame('gpt-4o-mini', $payload['model']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('user', $payload['messages'][1]['role']);
        $this->assertSame('assistant', $payload['messages'][2]['role']);
        $this->assertSame('tool', $payload['messages'][3]['role']);
        $this->assertSame('call_1', $payload['messages'][3]['tool_call_id']);
        $this->assertSame('function', $payload['tools'][0]['type']);
        $this->assertSame('auto', $payload['tool_choice']);
        $this->assertSame('Bearer test-openai-key', $captured->header('Authorization')[0] ?? null);

        $this->assertSame(ModelFinishReason::ToolCalls, $response->finishReason);
        $this->assertSame('search_products', $response->toolCalls[0]->name);
        $this->assertSame('call_abc', $response->toolCalls[0]->callId);
        $this->assertSame('فستان', $response->toolCalls[0]->arguments['query']);
        $this->assertSame(20, $response->usage?->inputTokens);
        $this->assertSame(ProviderId::OPENAI, $response->usage?->provider);
    }

    public function test_content_filter_is_a_safety_stop(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => '']]],
        ])]);

        $response = (new OpenAiAdapter)->generate($this->settings(), $this->plainRequest());

        $this->assertSame(ModelFinishReason::Safety, $response->finishReason);
        $this->assertNull($response->text);
    }

    public function test_missing_key_is_rejected_before_http(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        try {
            (new OpenAiAdapter)->generate($this->settings(), $this->plainRequest());
            $this->fail('Missing key was accepted.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::MISSING_API_KEY, $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    private function settings(): ResolvedModelSettings
    {
        return new ResolvedModelSettings(3, 9, ProviderId::OPENAI, 'gpt-4o-mini', 0.4, 256);
    }

    private function request(): ModelRequest
    {
        return new ModelRequest(
            employeeId: 3,
            runMode: AiRunMode::Live,
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
                new ModelToolCall('call_1', 'search_products', ['query' => 'فستان']),
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
