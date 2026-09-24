<?php

namespace Tests\Unit\AI\Inbox;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\InboxAgentOrchestrator;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class InboxAgentPersonaContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.enabled' => true,
            'ai.inbox_agent.tools_enabled' => true,
            'ai.inbox_agent.max_iterations' => 3,
            'ai.inbox_agent.max_tool_rounds' => 2,
        ]);

        $registry = new InboxAgentToolRegistry;
        $registry->register(app(SearchProductsTool::class));
        $this->app->instance(InboxAgentToolRegistry::class, $registry);
    }

    public function test_workspace_a_persona_does_not_leak_to_workspace_b(): void
    {
        $personaA = [
            'version' => 1,
            'identity' => ['name' => 'Sara-A', 'role' => 'Sales & Support Assistant'],
            'traits' => ['friendly'],
            'tone' => [
                'language' => 'en',
                'dialect' => 'neutral',
                'formality' => 'professional',
                'warmth' => 'medium',
                'verbosity' => 'concise',
                'emoji_usage' => 'none',
            ],
            'style' => [
                'greeting_style' => 'friendly',
                'sales_style' => 'helpful_not_pushy',
                'support_style' => 'empathetic_and_direct',
            ],
            'behavioral_rules' => ['Rule A'],
        ];

        $workspaceA = Workspace::factory()->create();
        AiSetting::factory()->create([
            'workspace_id' => $workspaceA->id,
            'agent_persona' => $personaA,
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
        ]);

        $workspaceB = Workspace::factory()->create();
        AiSetting::factory()->create([
            'workspace_id' => $workspaceB->id,
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
        ]);

        $contextA = $this->buildContext($workspaceA);
        $contextB = $this->buildContext($workspaceB);

        $this->assertSame('Sara-A', $contextA->persona->identityName);
        $this->assertSame('workspace', $contextA->personaSource);
        $this->assertSame('Assistant', $contextB->persona->identityName);
        $this->assertSame('default', $contextB->personaSource);
        $this->assertNotSame($contextA->personaFingerprint, $contextB->personaFingerprint);
    }

    public function test_persona_in_system_prompt_once_and_separate_from_user_message(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();

        $context = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));

        $system = (string) $context->messages[0]['content'];
        $user = (string) $context->messages[1]['content'];

        $this->assertSame(1, substr_count($system, '## Agent Persona'));
        $this->assertStringContainsString('Identity:', $system);
        $this->assertStringContainsString('System Safety (highest precedence)', $system);
        $this->assertStringNotContainsString('## Agent Persona', $user);
        $this->assertSame("UNTRUSTED CUSTOMER CONTENT:\n".$message->content, $user);
    }

    public function test_persona_does_not_alter_tool_arguments(): void
    {
        config(['services.google_ai_studio.key' => 'test-key']);

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'thoughtSignature' => 'sig-1',
                                'functionCall' => [
                                    'id' => 'call-1',
                                    'name' => 'search_products',
                                    'args' => (object) ['query' => 'shoes'],
                                ],
                            ]],
                        ],
                    ]],
                    'usageMetadata' => ['totalTokenCount' => 5],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'Found shoes']]],
                    ]],
                    'usageMetadata' => ['totalTokenCount' => 5],
                ], 200),
        ]);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures([
            'agent_persona' => [
                'version' => 1,
                'identity' => ['name' => 'Sara', 'role' => 'Sales & Support Assistant'],
                'traits' => ['confident'],
                'tone' => [
                    'language' => 'en',
                    'dialect' => 'neutral',
                    'formality' => 'professional',
                    'warmth' => 'high',
                    'verbosity' => 'detailed',
                    'emoji_usage' => 'moderate',
                ],
                'style' => [
                    'greeting_style' => 'warm',
                    'sales_style' => 'consultative',
                    'support_style' => 'patient_and_detailed',
                ],
                'behavioral_rules' => ['Be confident and concise.'],
            ],
        ]);

        $result = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentOrchestrator::class)->run(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));

        $this->assertTrue($result->isSuccessful());
        Http::assertSentCount(2);

        $recorded = Http::recorded();
        $firstRequest = $recorded[0][0]->data();
        $this->assertArrayHasKey('tools', $firstRequest);
        $this->assertSame('search_products', $firstRequest['tools'][0]['functionDeclarations'][0]['name']);
    }

    public function test_precedence_and_tool_truth_sections_present(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));
        $system = (string) $context->messages[0]['content'];

        $safetyPos = strpos($system, 'System Safety');
        $personaPos = strpos($system, '## Agent Persona');
        $truthPos = strpos($system, 'Persona vs factual data');

        $this->assertNotFalse($safetyPos);
        $this->assertNotFalse($personaPos);
        $this->assertNotFalse($truthPos);
        $this->assertTrue($safetyPos < $personaPos);
        $this->assertTrue($personaPos < $truthPos);
    }

    public function test_persona_on_context_is_separate_from_tool_results_in_messages(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));

        $updated = $context->withMessages([
            ...$context->messages,
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [['name' => 'search_products', 'call_id' => 'c1', 'arguments' => ['query' => 'x']]],
            ],
            [
                'role' => 'tool',
                'tool_name' => 'search_products',
                'tool_call_id' => 'c1',
                'content' => json_encode(['success' => true, 'result' => ['count' => 1]]),
            ],
        ]);

        $this->assertSame($context->personaFingerprint, $updated->personaFingerprint);
        $this->assertStringContainsString('Persona vs factual data', (string) $updated->messages[0]['content']);
        $this->assertSame('tool', $updated->messages[count($updated->messages) - 1]['role']);
    }

    public function test_observability_metadata_includes_persona_fingerprint(): void
    {
        $fake = new FakeAiProvider;
        $fake->content = 'ok';
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentOrchestrator::class)->run(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));

        $this->assertArrayHasKey('agent_persona_fingerprint', $result->metadata);
        $this->assertSame('default', $result->metadata['agent_persona_source']);
    }

    private function buildContext(Workspace $workspace): InboxAgentContext
    {
        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
        ]);
        $message = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'hello',
        ]);
        $setting = AiSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        return app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));
    }

    /**
     * @param  array<string, mixed>  $settingOverrides
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting}
     */
    private function seedFixtures(array $settingOverrides = []): array
    {
        $workspace = Workspace::factory()->create();

        $setting = AiSetting::factory()->create(array_merge([
            'workspace_id' => $workspace->id,
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
        ], $settingOverrides));

        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
        ]);
        $message = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Do you have shoes?',
        ]);

        return [$workspace, $conversation, $message, $setting];
    }
}
