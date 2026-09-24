<?php

namespace Tests\Unit\Chat;

use App\Models\ChatCatalogItem;
use App\Models\PosMenuItem;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Support\Chat\ChatAiRole;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatCatalogRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_chat_catalog_matches_only(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'فستان أسود',
                'slug' => 'dress-black',
                'description' => 'فستان سهرة',
                'price' => 200,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'تيشيرت أبيض',
                'slug' => 'tee-white',
                'price' => 40,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
            Product::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'فستان مالي',
                'slug' => 'finance-dress',
                'sku' => 'FIN-DRESS',
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'status' => 'active',
            ]);
            PosMenuItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'فستان كاشير',
                'is_active' => true,
            ]);
        });

        $rows = app(ChatCatalogRetrievalService::class)
            ->searchForPrompt($workspace, 'هل عندكم فستان أسود مقاس M؟');

        $names = collect($rows)->pluck('name')->all();
        $this->assertContains('فستان أسود', $names);
        $this->assertNotContains('فستان مالي', $names);
        $this->assertNotContains('فستان كاشير', $names);
        $this->assertNotContains('تيشيرت أبيض', $names);
    }

    public function test_empty_or_unrelated_query_does_not_dump_catalog(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'استشارة محاسبية',
                'slug' => 'consulting',
                'item_kind' => 'service',
                'tangibility' => 'intangible',
                'price' => 300,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        });

        $service = app(ChatCatalogRetrievalService::class);
        $this->assertSame([], $service->searchForPrompt($workspace, 'مرحبا'));
        $this->assertSame([], $service->searchForPrompt($workspace, 'هل عندكم يخت فضائي؟'));
    }

    public function test_ai_roles_are_normalized(): void
    {
        $this->assertSame(ChatAiRole::SALES_AGENT, ChatAiRole::normalize('sales_agent'));
        $this->assertSame(ChatAiRole::GENERAL, ChatAiRole::normalize('not-a-role'));
        $this->assertStringContainsString('كتالوج الشات', ChatAiRole::instructions(ChatAiRole::SALES_AGENT));
        $this->assertStringContainsString('دعم', ChatAiRole::instructions(ChatAiRole::CUSTOMER_SUPPORT));
    }
}
