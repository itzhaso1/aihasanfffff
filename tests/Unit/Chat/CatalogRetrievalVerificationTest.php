<?php

namespace Tests\Unit\Chat;

use App\AI\Context\AiContextProvider;
use App\AI\Context\ConversationState;
use App\AI\Context\ReferenceResolver;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\FactRecord;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;
use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CatalogRetrievalVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSE = 'وش متوفر عندكم؟';

    private const PRODUCTS = 'ما هي المنتجات المتاحة؟';

    public function test_hide_policy_keeps_available_items_and_drops_unavailable_ones_on_every_read_path(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $visible = $this->createItem($workspace, 'دفتر متاح', stock: 4);
        $hidden = $this->createItem($workspace, 'دفتر نفد', stock: 0);
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::Hide);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, self::BROWSE);
        $search = app(ChatCatalogRetrievalService::class)->searchForAi($workspace, self::BROWSE);
        $toolNames = $this->toolProductNames($workspace, self::BROWSE);
        $facts = $this->factsFor($workspace, self::BROWSE);
        $encoded = json_encode([$lookup, $search, $toolNames, $this->factPayload($facts)], JSON_UNESCAPED_UNICODE);

        $this->assertSame('listed', $lookup['status']);
        $this->assertSame(['دفتر متاح'], array_column($lookup['items'], 'name'));
        $this->assertSame((int) $visible->id, $lookup['items'][0]['catalog_item_id']);
        $this->assertSame(['دفتر متاح'], array_column($search, 'name'));
        $this->assertSame(['دفتر متاح'], $toolNames);
        $this->assertStringNotContainsString('دفتر نفد', (string) $encoded);
        $this->assertNotContains((int) $hidden->id, array_column($lookup['items'], 'catalog_item_id'));
        $this->assertNotContains((int) $hidden->id, array_column($search, 'catalog_item_id'));
        $this->assertTrue(collect($facts)->contains(
            fn (FactRecord $fact): bool => $fact->key === 'catalog_item:'.$visible->id.'.name',
        ));
        $this->assertFalse(collect($facts)->contains(
            fn (FactRecord $fact): bool => str_contains($fact->key, (string) $hidden->id) || $fact->value === 'دفتر نفد',
        ));
    }

    public function test_show_unavailable_policy_still_returns_the_out_of_stock_item(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createItem($workspace, 'دفتر متاح', stock: 4);
        $this->createItem($workspace, 'دفتر نفد', stock: 0);
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::ShowUnavailable);

        $names = array_column(
            app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, self::BROWSE)['items'],
            'name',
        );

        $this->assertEqualsCanonicalizing(['دفتر متاح', 'دفتر نفد'], $names);
    }

    public function test_catalog_page_marks_has_more_only_when_another_eligible_item_exists(): void
    {
        $seven = $this->catalogWithProducts(7);
        $eight = $this->catalogWithProducts(8);
        $nine = $this->catalogWithProducts(9);
        $hiddenExtra = $this->catalogWithProducts(8);
        $this->createItem($hiddenExtra, 'منتج مخفي', stock: 0);
        $this->publishCatalog($hiddenExtra, ChatCatalogUnavailableBehavior::Hide);

        $sevenLookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($seven, self::BROWSE);
        $eightLookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($eight, self::BROWSE);
        $nineLookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($nine, self::BROWSE);
        $hiddenLookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($hiddenExtra, self::BROWSE);
        $nineContext = (string) ($this->contextPayload($nine, self::BROWSE)['context.catalog'] ?? '');
        $eightContext = (string) ($this->contextPayload($eight, self::BROWSE)['context.catalog'] ?? '');

        $this->assertSame(7, count($sevenLookup['items']));
        $this->assertFalse($sevenLookup['has_more']);
        $this->assertSame(8, count($eightLookup['items']));
        $this->assertFalse($eightLookup['has_more']);
        $this->assertSame(8, count($nineLookup['items']));
        $this->assertTrue($nineLookup['has_more']);
        $this->assertSame(8, count($hiddenLookup['items']));
        $this->assertFalse($hiddenLookup['has_more']);
        $this->assertStringNotContainsString('منتج مخفي', json_encode($hiddenLookup, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('partial', $nineContext);
        $this->assertStringContainsString('Do not say this is the complete catalog.', $nineContext);
        $this->assertStringContainsString('complete', $eightContext);
        $this->assertStringNotContainsString('Do not say this is the complete catalog.', $eightContext);
    }

    public function test_services_only_catalog_is_not_reported_as_empty_or_as_a_missing_product_name(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createItem($workspace, 'استشارة تصميم', stock: null, kind: 'service');
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::ShowUnavailable);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, self::PRODUCTS);
        $context = (string) ($this->contextPayload($workspace, self::PRODUCTS)['context.catalog'] ?? '');

        $this->assertSame('no_matching_type', $lookup['status']);
        $this->assertFalse($lookup['has_more']);
        $this->assertSame([], array_column($lookup['items'], 'name'));
        $this->assertStringContainsString('none of the requested type', $context);
        $this->assertStringContainsString('Do not say the catalog is empty', $context);
    }

    public function test_mixed_catalog_product_question_returns_products_only(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createItem($workspace, 'دفتر ملاحظات', stock: 3);
        $this->createItem($workspace, 'استشارة تصميم', stock: null, kind: 'service');
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::ShowUnavailable);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, self::PRODUCTS);
        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: self::PRODUCTS,
        );

        $this->assertSame('listed', $lookup['status']);
        $this->assertSame(['دفتر ملاحظات'], array_column($lookup['items'], 'name'));
        $this->assertSame(['دفتر ملاحظات'], array_column($resolution->catalogItems, 'name'));
        $this->assertNotSame('catalog_empty', $lookup['status']);
        $this->assertNotSame('name_not_found', $lookup['status']);
    }

    public function test_price_and_availability_facts_are_absent_unless_the_lookup_is_listed(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createItem($workspace, 'دفتر ملاحظات', stock: 2);
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::ShowUnavailable);
        $emptyWorkspace = Workspace::factory()->create(['type' => 'company']);

        $missing = $this->factsFor($workspace, 'هل عندكم حذاء أحمر؟');
        $empty = $this->factsFor($emptyWorkspace, self::BROWSE);
        $greeting = $this->factsFor($workspace, 'مرحبا');
        $this->assertSame('name_not_found', $this->contextStatus($workspace, 'هل عندكم حذاء أحمر؟'));
        $this->assertSame('catalog_empty', $this->contextStatus($emptyWorkspace, self::BROWSE));
        $this->assertSame('not_loaded', $this->contextStatus($workspace, 'مرحبا'));

        $root = Mockery::mock(ChatCatalogRootService::class);
        $root->shouldReceive('forWorkspace')->andThrow(new RuntimeException('SQLSTATE secret details'));
        $this->app->instance(ChatCatalogRootService::class, $root);
        $failed = app(ChatCatalogRetrievalService::class)->lookupForAssistant(
            Workspace::factory()->create(['type' => 'company']),
            self::BROWSE,
        );

        $this->assertSame('search_failed', $failed['status']);
        foreach ([$missing, $empty, $greeting] as $facts) {
            $this->assertFalse(collect($facts)->contains(
                fn (FactRecord $fact): bool => str_ends_with($fact->key, '.price') || str_ends_with($fact->key, '.availability') || $fact->key === 'result_status',
            ));
        }
        $this->assertSame([], $failed['items']);
    }

    public function test_another_workspace_product_is_absent_from_lookup_and_facts(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $other = Workspace::factory()->create(['type' => 'company']);
        $this->createItem($workspace, 'دفتر محلي', stock: 2);
        $foreign = $this->createItem($other, 'دفتر خارجي', stock: 2);
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::ShowUnavailable);
        $this->publishCatalog($other, ChatCatalogUnavailableBehavior::ShowUnavailable);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, self::BROWSE);
        $facts = $this->factsFor($workspace, self::BROWSE);
        $encoded = json_encode([$lookup, $this->factPayload($facts)], JSON_UNESCAPED_UNICODE);

        $this->assertSame(['دفتر محلي'], array_column($lookup['items'], 'name'));
        $this->assertNotContains((int) $foreign->id, array_column($lookup['items'], 'catalog_item_id'));
        $this->assertStringNotContainsString('دفتر خارجي', (string) $encoded);
        $this->assertFalse(collect($facts)->contains(
            fn (FactRecord $fact): bool => str_contains($fact->key, 'catalog_item:'.$foreign->id) || $fact->value === 'دفتر خارجي',
        ));
    }

    private function catalogWithProducts(int $count): Workspace
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        foreach (range(1, $count) as $index) {
            $this->createItem($workspace, 'منتج '.$index, stock: 2);
        }
        $this->publishCatalog($workspace, ChatCatalogUnavailableBehavior::ShowUnavailable);

        return $workspace;
    }

    private function publishCatalog(Workspace $workspace, ChatCatalogUnavailableBehavior $behavior): void
    {
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->publish($roots->forWorkspace($workspace));
        $catalog->forceFill(['unavailable_item_behavior' => $behavior])->save();
    }

    private function createItem(Workspace $workspace, string $name, ?int $stock, string $kind = 'product'): ChatCatalogItem
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $name, $stock, $kind): ChatCatalogItem {
            return app(ChatCatalogService::class)->createItem($workspace, [
                'name' => $name,
                'description' => 'وصف '.$name,
                'price' => '25.00',
                'currency' => 'SAR',
                'item_kind' => $kind,
                'tangibility' => $kind === 'service' ? 'intangible' : 'tangible',
                'classification' => $kind === 'service' ? 'service' : 'physical',
                'inventory_policy' => $stock === null ? 'not_tracked' : 'finite_stock',
                'stock' => $stock,
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function toolProductNames(Workspace $workspace, string $query): array
    {
        [$conversation, $message, $setting] = $this->conversation($workspace, $query);
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        $result = app(SearchProductsTool::class)->execute($context, ['query' => $query]);

        return array_column($result->payload['products'] ?? [], 'name');
    }

    /**
     * @return list<FactRecord>
     */
    private function factsFor(Workspace $workspace, string $text): array
    {
        return app(AiContextProvider::class)->build(
            $this->request($workspace, $text),
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['product_search']),
        )->facts;
    }

    private function contextStatus(Workspace $workspace, string $text): string
    {
        return (string) app(AiContextProvider::class)->build(
            $this->request($workspace, $text),
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['product_search']),
        )->structuredContext?->workspacePolicyContext['business_capabilities']['products']['status'];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextPayload(Workspace $workspace, string $text): array
    {
        $payload = app(AiContextProvider::class)->build(
            $this->request($workspace, $text),
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['product_search']),
        );
        $encoded = [];
        foreach ($payload->sections as $section) {
            $encoded[$section->key] = $section->content;
        }
        $encoded['products_status'] = $payload->structuredContext?->workspacePolicyContext['business_capabilities']['products'] ?? null;

        return $encoded;
    }

    /**
     * @param  list<FactRecord>  $facts
     * @return list<array{source: string, key: string, value: string|int|float|bool|null}>
     */
    private function factPayload(array $facts): array
    {
        return array_map(static fn (FactRecord $fact): array => $fact->toArray(), $facts);
    }

    private function request(Workspace $workspace, string $text): AiRequest
    {
        [, $message] = $this->conversation($workspace, $text);

        return new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $message->conversation_id,
            employeeId: 7,
            message: new AiInboundMessage($text, (int) $message->id),
            runMode: AiRunMode::Live,
            channel: 'web',
        );
    }

    /**
     * @return array{0: Conversation, 1: Message, 2: AiSetting}
     */
    private function conversation(Workspace $workspace, string $text): array
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $text): array {
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
            $setting = AiSetting::query()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'name' => 'Assistant',
                    'instructions' => 'كن مختصرًا.',
                    'provider' => 'google_ai_studio',
                    'model' => 'gemini-2.5-flash',
                    'is_active' => true,
                ],
            );

            return [$conversation, $message, $setting];
        });
    }
}
