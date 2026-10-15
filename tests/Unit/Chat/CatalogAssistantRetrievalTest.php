<?php

namespace Tests\Unit\Chat;

use App\AI\Context\AiContextBuilder;
use App\AI\Context\AiContextProvider;
use App\AI\Context\ConversationState;
use App\AI\Context\ReferenceResolver;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\FactRecorder;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CatalogAssistantRetrievalTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_availability_question_lists_saved_products_instead_of_searching_the_request_verb(): void
    {
        [$workspace, $itemId] = $this->workspaceWithItem('دفتر ملاحظات', 'دفتر ورقي للطلاب', '120.00');
        $this->workspaceWithItem('منتج مساحة أخرى', 'لا يجب أن يظهر', '10.00');

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant(
            $workspace,
            'قولي إيش متوفر عندك طيب',
        );

        $this->assertSame('listed', $lookup['status']);
        $this->assertSame(['دفتر ملاحظات'], array_column($lookup['items'], 'name'));
        $this->assertSame($itemId, $lookup['items'][0]['catalog_item_id']);
        $this->assertSame('120.00', $lookup['items'][0]['price']);
        $this->assertSame('in_stock', $lookup['items'][0]['availability']);
        $this->assertSame('دفتر ورقي للطلاب', $lookup['items'][0]['description']);
    }

    public function test_specific_product_search_and_a_miss_stay_distinct_from_an_empty_catalog(): void
    {
        [$workspace] = $this->workspaceWithItem('دفتر ملاحظات', 'وصف', '120.00');
        $service = app(ChatCatalogRetrievalService::class);

        $found = $service->lookupForAssistant($workspace, 'هل عندكم دفتر ملاحظات؟');
        $missing = $service->lookupForAssistant($workspace, 'هل عندكم حذاء أحمر؟');
        $emptyWorkspace = Workspace::factory()->create(['type' => 'company']);
        $empty = $service->lookupForAssistant($emptyWorkspace, 'قولي إيش متوفر عندك طيب');
        $greeting = $service->lookupForAssistant($workspace, 'مرحبا');

        $this->assertSame('listed', $found['status']);
        $this->assertSame('دفتر ملاحظات', $found['items'][0]['name']);
        $this->assertSame('name_not_found', $missing['status']);
        $this->assertSame([], $missing['items']);
        $this->assertSame('catalog_empty', $empty['status']);
        $this->assertSame('not_loaded', $greeting['status']);
    }

    public function test_several_products_are_kept_and_reach_the_assistant_context(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createItem($workspace, 'دفتر ملاحظات', 'وصف الدفتر', '120.00');
        $this->createItem($workspace, 'قلم أزرق', 'وصف القلم', '15.00');

        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: 'وش متوفر عندكم؟',
        );
        $request = $this->request($workspace, 'وش متوفر عندكم؟');
        $context = app(AiContextBuilder::class)->build(
            $request,
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['product_search']),
        );
        $payload = app(AiContextProvider::class)->build(
            $request,
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['product_search']),
        );
        $catalog = '';
        foreach ($payload->sections as $section) {
            if ($section->key === 'context.catalog') {
                $catalog = $section->content;
            }
        }

        $this->assertTrue($resolution->resolved);
        $this->assertCount(2, $resolution->productIds);
        $this->assertSame('listed', $context->workspacePolicyContext['business_capabilities']['products']['status']);
        $this->assertSame('listed', $context->workspacePolicyContext['business_capabilities']['pricing']['status']);
        $this->assertSame('listed', $context->workspacePolicyContext['business_capabilities']['availability']['status']);
        $this->assertStringContainsString('دفتر ملاحظات', $catalog);
        $this->assertStringContainsString('قلم أزرق', $catalog);
        $this->assertStringContainsString('120.00', $catalog);
        $this->assertStringContainsString('in_stock', $catalog);
        $this->assertTrue(collect($payload->facts)->contains(
            fn (FactRecord $fact): bool => str_ends_with($fact->key, '.price') && (string) $fact->value === '120.00',
        ));
    }

    public function test_a_renamed_product_is_read_from_the_catalog_on_the_next_question(): void
    {
        [$workspace, $itemId] = $this->workspaceWithItem('دفتر ملاحظات', 'وصف', '120.00');
        ChatCatalogItem::withoutGlobalScopes()->whereKey($itemId)->update([
            'name' => 'دفتر محدّث',
            'price' => '80.00',
        ]);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'إيش عندكم؟');

        $this->assertSame(['دفتر محدّث'], array_column($lookup['items'], 'name'));
        $this->assertSame('80.00', $lookup['items'][0]['price']);
    }

    public function test_lookup_failure_is_not_reported_as_an_empty_catalog(): void
    {
        $root = Mockery::mock(ChatCatalogRootService::class);
        $root->shouldReceive('forWorkspace')->andThrow(new RuntimeException('SQLSTATE secret details'));
        $this->app->instance(ChatCatalogRootService::class, $root);
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'ورني المتوفر');

        $this->assertSame('search_failed', $lookup['status']);
        $this->assertSame([], $lookup['items']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($lookup, JSON_UNESCAPED_UNICODE));
    }

    public function test_availability_arrays_become_truth_check_facts_without_inventing_stock(): void
    {
        $facts = app(FactRecorder::class)->facts(CanonicalTools::SEARCH_PRODUCTS, [], [
            'count' => 1,
            'products' => [[
                'name' => 'دفتر ملاحظات',
                'price' => '120.00',
                'availability' => ['available' => true, 'label' => 'available', 'quantity' => null],
            ]],
        ]);
        $availability = collect($facts)->first(
            fn (FactRecord $fact): bool => str_ends_with($fact->key, '.availability'),
        );
        $guard = new HasimResponseGuard;
        $grounded = $guard->review($this->guardInput('المنتج متوفر وسعره 120.00 ريال', [
            new FactRecord('chat_catalog', 'catalog_item:1.name', 'دفتر ملاحظات'),
            new FactRecord('chat_catalog', 'catalog_item:1.price', '120.00'),
            new FactRecord('chat_catalog', 'catalog_item:1.availability', 'in_stock'),
        ]));
        $invented = $guard->review($this->guardInput('سعره 999 ريال', [
            new FactRecord('chat_catalog', 'catalog_item:1.price', '120.00'),
        ]));

        $this->assertNotNull($availability);
        $this->assertSame('in_stock', $availability->value);
        $this->assertSame(GuardVerdict::Pass, $grounded->verdict);
        $this->assertSame(GuardVerdict::Rejected, $invented->verdict);
    }

    /**
     * @return array{0: Workspace, 1: int}
     */
    private function workspaceWithItem(string $name, string $description, string $price): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $item = $this->createItem($workspace, $name, $description, $price);

        return [$workspace, (int) $item->id];
    }

    private function createItem(Workspace $workspace, string $name, string $description, string $price): ChatCatalogItem
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $name, $description, $price): ChatCatalogItem {
            return app(ChatCatalogService::class)->createItem($workspace, [
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'currency' => 'SAR',
            ]);
        });
    }

    private function request(Workspace $workspace, string $text): AiRequest
    {
        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $message = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'content' => $text,
        ]);

        return new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            employeeId: 7,
            message: new AiInboundMessage($text, (int) $message->id),
            runMode: AiRunMode::Live,
            channel: 'web',
        );
    }

    /**
     * @param  list<FactRecord>  $facts
     */
    private function guardInput(string $text, array $facts): GuardInput
    {
        return new GuardInput(
            request: new AiRequest(
                workspaceId: 1,
                conversationId: 1,
                employeeId: 1,
                message: new AiInboundMessage('وش متوفر عندكم؟'),
                runMode: AiRunMode::Live,
            ),
            text: $text,
            facts: $facts,
        );
    }
}
