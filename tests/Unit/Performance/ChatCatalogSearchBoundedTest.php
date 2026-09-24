<?php

namespace Tests\Unit\Performance;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatCatalogSearchBoundedTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_result_count_is_bounded(): void
    {
        $workspace = Workspace::factory()->create();
        $category = ChatCatalogCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Menu',
            'slug' => 'menu',
        ]);

        for ($i = 0; $i < 30; $i++) {
            ChatCatalogItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'chat_catalog_category_id' => $category->id,
                'name' => 'Coffee '.$i,
                'slug' => 'coffee-'.$i,
                'sku' => 'CF-'.$i,
                'price' => '10.00',
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        }

        $results = app(WorkspaceContext::class)->runFor($workspace, fn () => app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'Coffee', 12));

        $this->assertLessThanOrEqual(12, count($results));
    }
}
