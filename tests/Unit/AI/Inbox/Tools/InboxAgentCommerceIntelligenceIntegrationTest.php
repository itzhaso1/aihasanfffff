<?php

namespace Tests\Unit\AI\Inbox\Tools;

use App\Enums\Commerce\CommerceEvaluationStatus;
use App\Models\AiSetting;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\Context\InboxAgentContextEngine;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\InboxAgentPromptBuilder;
use App\Services\AI\Inbox\Tools\EvaluateProductSuitabilityTool;
use App\Services\AI\Inbox\Tools\GetProductIntelligenceTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolCall;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutor;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SaveCustomerCommerceContextTool;
use App\Services\Commerce\Intelligence\CommerceAttributeService;
use App\Services\Commerce\Intelligence\CommerceCustomerContextService;
use App\Services\Commerce\Intelligence\CommerceRequirementService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxAgentCommerceIntelligenceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private User|Customer $customer;

    private Conversation $conversation;

    private Message $message;

    private AiSetting $setting;

    private ChatCatalogItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->otherWorkspace = Workspace::factory()->create();

        $this->customer = Customer::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Sara Al-Ahmad',
        ]);

        $this->conversation = Conversation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'customer_id' => $this->customer->id,
            'channel' => 'whatsapp',
        ]);

        $this->message = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'customer_id' => $this->customer->id,
            'direction' => 'inbound',
            'content' => 'بدي الفستان الأحمر، طولي 150 ووزني 56، بلبسني؟',
        ]);

        $this->setting = AiSetting::query()->create([
            'workspace_id' => $this->workspace->id,
            'ai_role' => 'general',
            'persona_tone' => 'friendly',
        ]);

        app(WorkspaceContext::class)->runFor($this->workspace, function (): void {
            $category = ChatCatalogCategory::query()->create([
                'workspace_id' => $this->workspace->id,
                'name' => 'Fashion Apparel',
            ]);

            $this->item = ChatCatalogItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'chat_catalog_category_id' => $category->id,
                'name' => 'Silk Red Dress',
                'slug' => 'silk-red-dress',
                'status' => 'active',
                'publication_status' => 'published',
                'price' => 350,
                'currency' => 'SAR',
            ]);

            app(CommerceAttributeService::class)->createDefinition($this->workspace, [
                'key' => 'bust_cm',
                'display_name' => 'محيط الصدر',
                'data_type' => 'measurement',
                'unit' => 'cm',
            ]);

            app(CommerceRequirementService::class)->createDefinition($this->workspace, [
                'key' => 'bust_cm',
                'label' => 'محيط الصدر',
                'data_type' => 'measurement',
                'unit' => 'cm',
                'is_required' => true,
                'applicability' => ['category_id' => (int) $category->id],
            ]);
        });

        $registry = app(InboxAgentToolRegistry::class);
        if (! $registry->has('evaluate_product_suitability')) {
            $registry->register(app(EvaluateProductSuitabilityTool::class));
        }
        if (! $registry->has('get_product_intelligence')) {
            $registry->register(app(GetProductIntelligenceTool::class));
        }
        if (! $registry->has('save_customer_commerce_context')) {
            $registry->register(app(SaveCustomerCommerceContextTool::class));
        }
    }

    public function test_evaluate_product_suitability_tool_returns_insufficient_information_when_bust_measurement_missing(): void
    {
        $contextBuilder = app(InboxAgentContextBuilder::class);
        $agentContext = $contextBuilder->build($this->workspace, $this->conversation, $this->message, $this->setting);

        $executor = app(InboxAgentToolExecutor::class);

        $result = $executor->executeOne($agentContext, new InboxAgentToolCall(
            toolName: 'evaluate_product_suitability',
            callId: 'call_eval_01',
            arguments: [
                'item_id' => (int) $this->item->id,
                'customer_context' => [
                    'height_cm' => 150,
                    'weight_kg' => 56,
                ],
            ],
        ));

        $this->assertTrue($result->success);
        $this->assertEquals('evaluate_product_suitability', $result->toolName);
        $this->assertEquals(CommerceEvaluationStatus::InsufficientInformation->value, $result->payload['evaluation']['status']);
        $this->assertFalse($result->payload['evaluation']['is_suitable']);
        $this->assertNotEmpty($result->payload['evaluation']['missing_requirements']);
    }

    public function test_evaluate_product_suitability_tool_returns_compatible_when_all_required_context_is_provided(): void
    {
        $contextBuilder = app(InboxAgentContextBuilder::class);
        $agentContext = $contextBuilder->build($this->workspace, $this->conversation, $this->message, $this->setting);

        $executor = app(InboxAgentToolExecutor::class);

        $result = $executor->executeOne($agentContext, new InboxAgentToolCall(
            toolName: 'evaluate_product_suitability',
            callId: 'call_eval_02',
            arguments: [
                'item_id' => (int) $this->item->id,
                'customer_context' => [
                    'bust_cm' => 90,
                    'height_cm' => 150,
                ],
            ],
        ));

        $this->assertTrue($result->success);
        $this->assertEquals(CommerceEvaluationStatus::Compatible->value, $result->payload['evaluation']['status']);
        $this->assertTrue($result->payload['evaluation']['is_suitable']);
        $this->assertEmpty($result->payload['evaluation']['missing_requirements']);

        // Verify explicit customer context was saved
        $stored = app(CommerceCustomerContextService::class)->getContextAsArray($this->workspace, (int) $this->customer->id);
        $this->assertEquals(90, $stored['bust_cm']);
    }

    public function test_get_product_intelligence_tool_returns_authoritative_profile(): void
    {
        $contextBuilder = app(InboxAgentContextBuilder::class);
        $agentContext = $contextBuilder->build($this->workspace, $this->conversation, $this->message, $this->setting);

        $executor = app(InboxAgentToolExecutor::class);

        $result = $executor->executeOne($agentContext, new InboxAgentToolCall(
            toolName: 'get_product_intelligence',
            callId: 'call_intel_01',
            arguments: [
                'item_id' => (int) $this->item->id,
            ],
        ));

        $this->assertTrue($result->success);
        $this->assertEquals((int) $this->item->id, $result->payload['intelligence_profile']['item_id']);
        $this->assertNotEmpty($result->payload['intelligence_profile']['requirements']);
    }

    public function test_save_customer_commerce_context_tool_persists_context(): void
    {
        $contextBuilder = app(InboxAgentContextBuilder::class);
        $agentContext = $contextBuilder->build($this->workspace, $this->conversation, $this->message, $this->setting);

        $executor = app(InboxAgentToolExecutor::class);

        $result = $executor->executeOne($agentContext, new InboxAgentToolCall(
            toolName: 'save_customer_commerce_context',
            callId: 'call_save_01',
            arguments: [
                'context_attributes' => [
                    'bust_cm' => 88,
                    'waist_cm' => 68,
                ],
            ],
        ));

        $this->assertTrue($result->success);

        $stored = app(CommerceCustomerContextService::class)->getContextAsArray($this->workspace, (int) $this->customer->id);
        $this->assertEquals(88, $stored['bust_cm']);
        $this->assertEquals(68, $stored['waist_cm']);
    }

    public function test_evaluate_product_suitability_rejects_cross_workspace_item(): void
    {
        $contextBuilder = app(InboxAgentContextBuilder::class);

        // Build context for other workspace
        $otherConversation = Conversation::factory()->create(['workspace_id' => $this->otherWorkspace->id]);
        $otherMessage = Message::factory()->create(['workspace_id' => $this->otherWorkspace->id, 'conversation_id' => $otherConversation->id, 'direction' => 'inbound']);
        $otherSetting = AiSetting::query()->create(['workspace_id' => $this->otherWorkspace->id]);

        $agentContext = $contextBuilder->build($this->otherWorkspace, $otherConversation, $otherMessage, $otherSetting);

        $executor = app(InboxAgentToolExecutor::class);

        $result = $executor->executeOne($agentContext, new InboxAgentToolCall(
            toolName: 'evaluate_product_suitability',
            callId: 'call_eval_cross',
            arguments: [
                'item_id' => (int) $this->item->id, // belongs to $this->workspace, not $otherWorkspace
            ],
        ));

        $this->assertFalse($result->success);
        $this->assertEquals('item_not_found', $result->errorCode);
    }

    public function test_inbox_agent_prompt_builder_includes_commerce_intelligence_guidelines(): void
    {
        $contextEngine = app(InboxAgentContextEngine::class);
        $structuredContext = $contextEngine->build($this->workspace, $this->conversation, $this->message, $this->setting);

        $promptBuilder = app(InboxAgentPromptBuilder::class);
        $rendered = $promptBuilder->render($structuredContext);

        $this->assertStringContainsString('Commerce Intelligence & Product Suitability Rules', $rendered['system']);
        $this->assertStringContainsString('evaluate_product_suitability', $rendered['system']);
    }
}
