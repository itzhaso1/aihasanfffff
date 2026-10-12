<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\Workspace;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Services\Chat\Storefront\PublicStorefrontRenderer;
use App\Services\Chat\Storefront\StorefrontTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicChatCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_and_storefront_routes_are_gone(): void
    {
        $workspace = Workspace::factory()->create([
            'type' => 'company',
            'slug' => 'public-catalog-workspace',
        ]);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->updateSettings($roots->forWorkspace($workspace), [
            'channel_visibility' => ['web' => true, 'chat' => true],
        ]);
        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'Visible Catalog Item',
                'price' => '19.50',
                'currency' => 'SAR',
                'sku' => 'PUBLIC-1',
            ]);
        });
        $roots->publish($catalog);

        $this->get('/catalog/'.$workspace->slug)->assertNotFound();
        $this->get('/catalog/'.$workspace->slug.'/'.$catalog->slug)->assertNotFound();
        $this->get('/catalog/'.$workspace->slug.'/data')->assertNotFound();
        $this->get('/catalog/'.$workspace->slug.'/products/visible-catalog-item')->assertNotFound();
        $this->get('/catalog/'.$workspace->slug.'/chat/room')->assertNotFound();

        $this->assertFalse(app('router')->has('public.chat-catalog.show'));
        $this->assertFalse(app('router')->has('public.chat-catalog.data'));
        $this->assertFalse(app('router')->has('public.chat-catalog.product'));
        $this->assertFalse(app('router')->has('workspace.chat-catalog.storefront'));
        $this->assertTrue(class_exists(ChatCatalogRetrievalService::class));
        $this->assertTrue(class_exists(SearchProductsTool::class));
        $this->assertFalse(class_exists(PublicStorefrontRenderer::class));
        $this->assertFalse(class_exists(StorefrontTemplateResolver::class));

        $index = file_get_contents(resource_path('views/workspace/chat-catalog/index.blade.php'));
        $settings = file_get_contents(resource_path('views/workspace/chat-catalog/settings.blade.php'));
        $this->assertStringNotContainsString('public.chat-catalog', $index.$settings);
        $this->assertStringNotContainsString('chat-catalog.storefront', $index.$settings);
        $this->assertStringNotContainsString('معاينة المتجر العام', $index.$settings);
        $this->assertStringNotContainsString('تصميم المتجر', $index.$settings);

        $rows = app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'Visible Catalog Item');
        $this->assertNotEmpty($rows);
        $this->assertSame('Visible Catalog Item', $rows[0]['name']);
        $this->assertArrayHasKey('price', $rows[0]);
        $this->assertArrayHasKey('availability', $rows[0]);
    }
}
