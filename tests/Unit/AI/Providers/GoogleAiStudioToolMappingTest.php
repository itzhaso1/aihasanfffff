<?php

namespace Tests\Unit\AI\Providers;

use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\AI\Providers\GoogleAiStudio\GoogleAiStudioContentsMapper;
use App\Services\AI\Providers\GoogleAiStudio\GoogleAiStudioToolResponseMapper;
use App\Services\AI\Providers\GoogleAiStudio\GoogleAiStudioToolSchemaMapper;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAiStudioToolMappingTest extends TestCase
{
    public function test_tool_definition_to_gemini_schema(): void
    {
        $registry = new InboxAgentToolRegistry;
        $registry->register(app(SearchProductsTool::class));

        $declarations = app(GoogleAiStudioToolSchemaMapper::class)->toFunctionDeclarations($registry->definitions());

        $this->assertSame('search_products', $declarations[0]['name']);
        $this->assertSame('OBJECT', $declarations[0]['parameters']['type']);
        $this->assertContains('query', $declarations[0]['parameters']['required']);
    }

    public function test_gemini_function_call_response_maps_to_neutral_tool_call(): void
    {
        $calls = app(GoogleAiStudioToolResponseMapper::class)->extractToolCalls([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'thoughtSignature' => 'sig-abc-123',
                        'functionCall' => [
                            'id' => 'provider-call-1',
                            'name' => 'search_products',
                            'args' => ['query' => 'shoes'],
                        ],
                    ]],
                ],
            ]],
        ]);

        $this->assertCount(1, $calls);
        $this->assertSame('search_products', $calls[0]['name']);
        $this->assertSame('provider-call-1', $calls[0]['call_id']);
        $this->assertSame('shoes', $calls[0]['arguments']['query']);
        $this->assertSame('sig-abc-123', $calls[0]['provider_metadata']['thought_signature']);
    }

    public function test_tool_messages_map_to_gemini_function_response_with_call_id(): void
    {
        $contents = app(GoogleAiStudioContentsMapper::class)->map([
            ['role' => 'user', 'content' => 'hello'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'name' => 'search_products',
                    'call_id' => 'provider-call-1',
                    'arguments' => ['query' => 'shoes'],
                    'provider_metadata' => [
                        'thought_signature' => 'sig-abc-123',
                    ],
                ]],
            ],
            [
                'role' => 'tool',
                'tool_name' => 'search_products',
                'tool_call_id' => 'provider-call-1',
                'content' => json_encode([
                    'tool' => 'search_products',
                    'call_id' => 'provider-call-1',
                    'success' => true,
                    'result' => ['count' => 1],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $this->assertSame('model', $contents[1]['role']);
        $this->assertSame('sig-abc-123', $contents[1]['parts'][0]['thoughtSignature'] ?? null);
        $this->assertSame('user', $contents[2]['role']);
        $this->assertSame('search_products', $contents[2]['parts'][0]['functionResponse']['name']);
        $this->assertSame('provider-call-1', $contents[2]['parts'][0]['functionResponse']['id']);
    }

    public function test_malformed_gemini_tool_call_response_yields_no_calls(): void
    {
        $calls = app(GoogleAiStudioToolResponseMapper::class)->extractToolCalls([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => 'plain answer']],
                ],
            ]],
        ]);

        $this->assertSame([], $calls);
    }

    public function test_provider_http_response_translates_tool_calls_and_normal_content(): void
    {
        config(['services.google_ai_studio.key' => 'test-key']);

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'thoughtSignature' => 'sig-native-1',
                                'functionCall' => [
                                    'id' => 'call-native-1',
                                    'name' => 'search_products',
                                    'args' => (object) ['query' => 'shoes'],
                                ],
                            ]],
                        ],
                    ]],
                    'usageMetadata' => ['totalTokenCount' => 11],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'parts' => [['text' => 'We have shoes in stock.']],
                        ],
                    ]],
                    'usageMetadata' => ['totalTokenCount' => 7],
                ], 200),
        ]);

        $registry = new InboxAgentToolRegistry;
        $registry->register(app(SearchProductsTool::class));

        $provider = app(GoogleAiStudioProvider::class);

        $first = $provider->generate(
            messages: [
                ['role' => 'system', 'content' => 'system'],
                ['role' => 'user', 'content' => 'find shoes'],
            ],
            model: 'gemini-2.5-flash',
            temperature: 0.2,
            maxTokens: 128,
            toolDefinitions: $registry->definitions(),
        );

        $this->assertSame('', $first['content']);
        $this->assertCount(1, $first['tool_calls']);
        $this->assertSame('call-native-1', $first['tool_calls'][0]['call_id']);

        $second = $provider->generate(
            messages: [
                ['role' => 'system', 'content' => 'system'],
                ['role' => 'user', 'content' => 'find shoes'],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => $first['tool_calls'],
                ],
                [
                    'role' => 'tool',
                    'tool_name' => 'search_products',
                    'tool_call_id' => 'call-native-1',
                    'content' => json_encode([
                        'tool' => 'search_products',
                        'call_id' => 'call-native-1',
                        'success' => true,
                        'result' => ['count' => 0, 'products' => []],
                    ]),
                ],
            ],
            model: 'gemini-2.5-flash',
            temperature: 0.2,
            maxTokens: 128,
            toolDefinitions: $registry->definitions(),
        );

        $this->assertSame('We have shoes in stock.', $second['content']);
        $this->assertArrayNotHasKey('tool_calls', $second);
    }
}
