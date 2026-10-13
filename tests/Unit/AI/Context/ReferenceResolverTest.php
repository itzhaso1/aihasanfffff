<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Context;

use App\AI\Context\ContextMessage;
use App\AI\Context\ConversationState;
use App\AI\Context\ReferenceResolver;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_state_reference_has_highest_precedence(): void
    {
        [$workspace, [$stateItem, $attachedItem]] = $this->workspaceWithPublishedCatalog(['فستان أسود', 'عباية سوداء']);

        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: ['catalog' => ['attached_product' => ['catalog_item_id' => $attachedItem]]],
            state: new ConversationState(referencedProductIds: [$stateItem]),
            currentMessage: 'كم',
            recentMessages: [new ContextMessage('customer', 'أبي العباية السوداء')],
        );

        $this->assertTrue($resolution->resolved);
        $this->assertSame('conversation_state', $resolution->source);
        $this->assertSame([$stateItem], $resolution->productIds);
    }

    public function test_attached_product_falls_back_when_state_is_empty(): void
    {
        [$workspace, [$itemId]] = $this->workspaceWithPublishedCatalog(['فستان أسود']);

        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: ['catalog' => ['attached_product' => ['catalog_item_id' => $itemId, 'price' => 250]]],
            state: new ConversationState,
            currentMessage: 'هذا',
        );

        $this->assertTrue($resolution->resolved);
        $this->assertSame('attached_product', $resolution->source);
        $this->assertSame([$itemId], $resolution->productIds);
    }

    public function test_recent_customer_reference_is_used_for_short_followups(): void
    {
        [$workspace, [$itemId]] = $this->workspaceWithPublishedCatalog(['فستان سهرة أسود']);

        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: 'كم',
            recentMessages: [new ContextMessage('customer', 'أبي فستان سهرة أسود')],
        );

        $this->assertFalse($resolution->resolved);
        $this->assertSame([], $resolution->productIds);
    }

    public function test_unresolved_and_cross_workspace_products_are_rejected(): void
    {
        [$workspaceA] = $this->workspaceWithPublishedCatalog(['فستان أسود', 'حذاء أسود']);
        [$workspaceB, [$foreignItemId]] = $this->workspaceWithPublishedCatalog(['فستان أزرق']);

        $foreign = app(ReferenceResolver::class)->resolve(
            workspace: $workspaceA,
            conversationMetadata: ['catalog' => ['attached_product' => ['catalog_item_id' => $foreignItemId]]],
            state: new ConversationState,
            currentMessage: 'هذا',
        );
        $ambiguous = app(ReferenceResolver::class)->resolve(
            workspace: $workspaceA,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: 'الاسود',
        );

        $this->assertFalse($foreign->resolved);
        $this->assertSame([], $foreign->productIds);
        $this->assertFalse($ambiguous->resolved);
        $this->assertSame([], $ambiguous->productIds);
        $this->assertSame(1, ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    /**
     * @param  list<string>  $names
     * @return array{0: Workspace, 1: list<int>}
     */
    private function workspaceWithPublishedCatalog(array $names): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $names): void {
            foreach ($names as $name) {
                app(ChatCatalogService::class)->createItem($workspace, [
                    'name' => $name,
                    'price' => '250.00',
                    'currency' => 'SAR',
                ]);
            }
        });

        $roots = app(ChatCatalogRootService::class);
        $roots->publish($roots->forWorkspace($workspace));

        return [
            $workspace,
            ChatCatalogItem::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        ];
    }
}
