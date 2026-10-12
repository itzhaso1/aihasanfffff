<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Context;

use App\AI\Context\AiContextBuilder;
use App\AI\Context\AiContextProvider;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ResolvedEmployee;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class AiContextBuilderTest extends TestCase
{
    use CreatesEmployees {
        employee as storeEmployee;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.context.max_messages' => 2,
            'ai.inbox_agent.context.max_message_chars' => 30,
            'ai.inbox_agent.context.max_history_chars' => 60,
        ]);
    }

    public function test_builder_is_bounded_deterministic_and_does_not_load_full_history(): void
    {
        [$workspace, $conversation, $current] = $this->conversationWithCatalog(
            ['فستان سهرة أسود'],
            ['history-1', str_repeat('x', 80), 'history-3', 'history-4'],
        );
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $context = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation, $current, 'كم'),
            $this->employee(),
        );

        $ids = array_column(array_map(static fn ($message): array => $message->toArray(), $context->recentMessages), 'message_id');
        $sorted = $ids;
        sort($sorted);

        $this->assertCount(2, $context->recentMessages);
        $this->assertSame($sorted, $ids);
        $this->assertLessThanOrEqual(60, array_sum(array_map(static fn ($message): int => mb_strlen($message->content), $context->recentMessages)));
        $this->assertTrue(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'from "messages"') && str_contains($sql, 'limit 2')));
    }

    public function test_context_excludes_sensitive_customer_fields_and_is_immutable(): void
    {
        [$workspace, $conversation, $current] = $this->conversationWithCatalog(['فستان سهرة أسود']);

        $context = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation, $current, 'كم'),
            $this->employee(),
        );

        $this->assertSame(['customer_id', 'display_name'], array_keys($context->customerContext));
        $this->assertArrayNotHasKey('phone', $context->customerContext);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $context->customerMessage = 'tampered';
    }

    public function test_provider_integration_carries_skill_keys_and_training_contract_fields(): void
    {
        [$workspace, $conversation, $current] = $this->conversationWithCatalog(
            ['فستان سهرة أسود'],
        );
        $itemId = (int) ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->value('id');
        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
            'metadata' => [
                'ai' => ['state' => ['employee_id' => 77, 'last_intent' => 'price_question', 'referenced_products' => [$itemId]]],
                'summary' => 'Customer is asking about a black evening dress.',
            ],
        ]);

        $payload = app(AiContextProvider::class)->build(
            new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: (int) $conversation->id,
                employeeId: 77,
                message: new AiInboundMessage('كم', (int) $current->id),
                runMode: AiRunMode::Live,
                channel: 'web',
                attributes: [
                    'employee_role' => 'sales_agent',
                    'employee_language' => 'ar',
                    'employee_dialect' => 'saudi',
                    'domain' => 'fashion',
                ],
            ),
            $this->employee(77, ['sales', 'product_search']),
        );

        $this->assertSame('price_question', $payload->intent);
        $this->assertSame(['sales', 'product_search'], $payload->structuredContext?->employeeContext['skill_keys']);
        $this->assertSame('sales_agent', $payload->structuredContext?->employeeContext['role']);
        $this->assertSame('ar', $payload->structuredContext?->employeeContext['language']);
        $this->assertSame('saudi', $payload->structuredContext?->employeeContext['dialect']);
        $this->assertSame('fashion', $payload->structuredContext?->employeeContext['domain']);
        $this->assertSame('Customer is asking about a black evening dress.', $payload->structuredContext?->conversationSummary);
        $this->assertArrayNotHasKey('display_name', $payload->structuredContext?->employeeContext ?? []);
    }

    public function test_employee_context_comes_from_the_stored_employee_not_request_attributes(): void
    {
        $workspace = $this->workspace('Stored employee context');
        $stored = $this->storeEmployee($workspace, [
            'name' => 'Layla',
            'role' => 'sales_agent',
            'training_profile' => 'default',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi'],
            'skills' => ['sales', 'product_search'],
        ]);
        $catalogs = ChatCatalog::withoutGlobalScopes()->count();

        $context = app(AiContextBuilder::class)->build(
            new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: null,
                employeeId: (int) $stored->id,
                message: new AiInboundMessage('انت من وين؟'),
                runMode: AiRunMode::Preview,
                attributes: [
                    'employee_role' => 'customer_support',
                    'employee_language' => 'en',
                    'employee_dialect' => 'egyptian',
                    'domain' => 'electronics',
                ],
            ),
            new ResolvedEmployee(
                employeeId: (int) $stored->id,
                configVersion: (string) $stored->config_version,
                skillKeys: $stored->skillKeys(),
            ),
        );

        $this->assertSame((int) $stored->id, $context->employeeContext['employee_id']);
        $this->assertSame('sales_agent', $context->employeeContext['role']);
        $this->assertSame('ar', $context->employeeContext['language']);
        $this->assertSame('saudi', $context->employeeContext['dialect']);
        $this->assertSame('default', $context->employeeContext['domain']);
        $this->assertSame(['product_search', 'sales'], $context->employeeContext['skill_keys']);
        $this->assertArrayNotHasKey('display_name', $context->employeeContext);
        $this->assertStringNotContainsString('Layla', (string) json_encode($context->employeeContext, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($context->workspacePolicyContext['catalog_open']);
        $this->assertSame('test', $context->workspacePolicyContext['business_capabilities']['mode']);
        $this->assertSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['services']['status']);
        $this->assertSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['products']['status']);
        $this->assertNull($context->workspacePolicyContext['business_capabilities']['services']['count']);
        $this->assertSame($catalogs, ChatCatalog::withoutGlobalScopes()->count());
    }

    public function test_contextual_short_messages_use_prior_context_and_attached_references(): void
    {
        [$workspace, $conversation, $current] = $this->conversationWithCatalog(
            ['فستان سهرة أسود'],
            ['أبي فستان سهرة أسود'],
        );
        $itemId = (int) ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->value('id');

        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
            'metadata' => [
                'catalog' => ['attached_product' => ['catalog_item_id' => $itemId]],
            ],
        ]);

        $purchase = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation->fresh(), $current, 'ابي هذا'),
            $this->employee(),
        );
        $availability = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation->fresh(), $current, 'والاسود موجود؟'),
            $this->employee(),
        );

        $this->assertSame('purchase_intent', $purchase->intent->canonical);
        $this->assertTrue($purchase->references->resolved);
        $this->assertSame('attached_product', $purchase->references->source);
        $this->assertSame('availability_question', $availability->intent->canonical);
        $this->assertSame([$itemId], $availability->references->productIds);
    }

    public function test_history_keeps_speaker_roles_and_drops_internal_notes(): void
    {
        [$workspace, $conversation, $current] = $this->conversationWithCatalog([]);
        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'content' => 'سؤال العميل',
        ]);
        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'ai_generated' => false,
            'content' => 'رد الموظف',
        ]);
        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'ai_generated' => true,
            'content' => 'رد المساعد',
        ]);
        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'internal_note',
            'content' => 'ملاحظة داخلية سرية',
        ]);

        config([
            'ai.inbox_agent.context.max_messages' => 8,
            'ai.inbox_agent.context.max_history_chars' => 4000,
        ]);

        $context = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation->fresh(), $current, 'كم السعر'),
            $this->employee(),
        );
        $roles = [];
        foreach ($context->recentMessages as $message) {
            $roles[$message->content] = $message->role;
        }

        $this->assertSame('customer', $roles['سؤال العميل']);
        $this->assertSame('human', $roles['رد الموظف']);
        $this->assertSame('ai', $roles['رد المساعد']);
        $this->assertArrayNotHasKey('ملاحظة داخلية سرية', $roles);
    }

    public function test_unsaved_suggestion_does_not_duplicate_the_latest_inbound(): void
    {
        [$workspace, $conversation] = $this->conversationWithCatalog(['فستان']);
        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'content' => 'هل الفستان متوفر',
        ]);

        config([
            'ai.inbox_agent.context.max_messages' => 8,
            'ai.inbox_agent.context.max_history_chars' => 4000,
        ]);

        $context = app(AiContextBuilder::class)->build(
            new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: (int) $conversation->id,
                employeeId: 7,
                message: new AiInboundMessage('هل الفستان متوفر'),
                runMode: AiRunMode::Live,
                channel: 'web',
            ),
            $this->employee(),
        );

        $this->assertSame('هل الفستان متوفر', $context->customerMessage);
        $this->assertSame(
            [],
            array_values(array_filter(
                $context->recentMessages,
                static fn ($message): bool => $message->content === 'هل الفستان متوفر',
            )),
        );
    }

    public function test_live_policy_slot_follows_loaded_catalog_policy_while_products_stay_unloaded(): void
    {
        [$workspace, $conversation, $current] = $this->conversationWithCatalog(['فستان']);

        $context = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation, $current, 'كم'),
            $this->employee(),
        );

        $this->assertSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['products']['status']);
        $this->assertSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['pricing']['status']);
        $this->assertNotSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['policies']['status']);
        $this->assertSame('catalog.customer_service_policy', $context->workspacePolicyContext['business_capabilities']['policies']['source']);
    }

    /**
     * @param  list<string>  $history
     * @param  array<string, mixed>  $metadata
     * @return array{0: Workspace, 1: Conversation, 2?: Message}
     */
    private function conversationWithCatalog(array $catalogNames, array $history = [], array $metadata = []): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company', 'name' => 'Context WS']);
        $customer = Customer::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Context Customer',
            'phone' => '+966500000001',
        ]);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $catalogNames): void {
            foreach ($catalogNames as $name) {
                app(ChatCatalogService::class)->createItem($workspace, [
                    'name' => $name,
                    'price' => '250.00',
                    'currency' => 'SAR',
                ]);
            }
        });
        $roots = app(ChatCatalogRootService::class);
        $roots->publish($roots->forWorkspace($workspace));

        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => $metadata,
        ]);

        foreach ($history as $index => $content) {
            Message::factory()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => $index % 2 === 0 ? 'inbound' : 'outbound',
                'content' => $content,
            ]);
        }

        $current = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'content' => 'placeholder',
        ]);

        return [$workspace, $conversation, $current];
    }

    private function request(Workspace $workspace, Conversation $conversation, Message $current, string $text): AiRequest
    {
        return new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            employeeId: 7,
            message: new AiInboundMessage($text, (int) $current->id),
            runMode: AiRunMode::Live,
            channel: 'web',
        );
    }

    /**
     * @param  list<string>  $skills
     */
    private function employee(int $id = 7, array $skills = ['sales']): ResolvedEmployee
    {
        return new ResolvedEmployee(employeeId: $id, configVersion: '1', skillKeys: $skills);
    }
}
