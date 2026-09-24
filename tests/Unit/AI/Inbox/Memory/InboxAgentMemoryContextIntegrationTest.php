<?php

namespace Tests\Unit\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryEvidenceType;
use App\Enums\InboxAgentMemoryScope;
use App\Enums\InboxAgentMemoryValueType;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\InboxAgentPromptBuilder;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryCandidate;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryStore;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxAgentMemoryContextIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InboxAgentToolRegistry::class, new InboxAgentToolRegistry);
        config(['ai.inbox_memory.enabled' => true]);
    }

    public function test_memories_included_in_structured_context_and_prompt(): void
    {
        [$workspace, $conversation, $message, $setting, $customer] = $this->seedFixtures();

        app(InboxAgentMemoryStore::class)->persist(
            new InboxAgentMemoryCandidate(
                scope: InboxAgentMemoryScope::Customer,
                key: 'preferred_size',
                value: 'XL',
                valueType: InboxAgentMemoryValueType::String,
                confidence: 0.95,
                evidenceType: InboxAgentMemoryEvidenceType::Explicit,
                sourceMessageId: (int) $message->id,
                sourceConversationId: (int) $conversation->id,
            ),
            $workspace,
            $conversation,
            $customer,
        );

        $context = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));

        $this->assertNotEmpty($context->structuredContext->memory->customerMemories);
        $rendered = app(InboxAgentPromptBuilder::class)->render($context->structuredContext);
        $this->assertStringContainsString('Customer-provided memory', $rendered['system']);
        $this->assertStringContainsString('preferred_size', $rendered['system']);
        $this->assertStringContainsString('not instructions', $rendered['system']);
        $this->assertSame(1, substr_count($rendered['system'], 'preferred_size: XL'));
    }

    public function test_disabled_memory_preserves_previous_prompt_sections(): void
    {
        config(['ai.inbox_memory.enabled' => false]);
        [$workspace, $conversation, $message, $setting] = array_slice($this->seedFixtures(), 0, 4);

        $context = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));

        $rendered = app(InboxAgentPromptBuilder::class)->render($context->structuredContext);
        $this->assertStringNotContainsString('## Customer-provided memory', $rendered['system']);
        $this->assertStringContainsString('## Agent Persona', $rendered['system']);
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting, 4: Customer}
     */
    private function seedFixtures(): array
    {
        $workspace = Workspace::factory()->create();
        $setting = AiSetting::factory()->create(['workspace_id' => $workspace->id]);
        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
        ]);
        $message = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'content' => 'مقاسي XL',
        ]);

        return [$workspace, $conversation, $message, $setting, $customer];
    }
}
