<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicChatCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_applies_web_visibility_and_customer_display_settings(): void
    {
        $workspace = Workspace::factory()->create([
            'type' => 'company',
            'slug' => 'public-catalog-workspace',
        ]);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->forWorkspace($workspace);
        $catalog = $roots->updateSettings($catalog, [
            'channel_visibility' => ['web' => true],
            'default_language' => 'en',
            'default_locale' => 'en_US',
            'rtl_mode' => false,
            'display_settings' => [
                'show_brand' => false,
                'show_sku' => true,
                'show_stock' => true,
                'show_sale_price' => true,
                'show_compare_at_price' => true,
                'price_display' => 'from_price',
                'decimal_places' => 0,
            ],
            'public_support_contact' => ['email' => 'support@example.test'],
            'legal_business_name' => 'Public Catalog LLC',
        ]);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'Visible Catalog Item',
                'price' => '19.50',
                'compare_at_price' => '25.00',
                'sale_price' => '15.25',
                'currency' => 'SAR',
                'sku' => 'PUBLIC-1',
                'brand' => 'Hidden Brand',
                'stock' => 3,
                'variants' => [
                    ['name' => 'Small', 'price' => '10.50', 'currency' => 'SAR', 'stock' => 3],
                ],
            ]);
        });
        $catalog = $roots->publish($catalog);

        $this->get('/catalog/'.$workspace->slug.'/'.$catalog->slug)
            ->assertOk()
            ->assertSee('Visible Catalog Item')
            ->assertSee('SKU: PUBLIC-1')
            ->assertSee('From')
            ->assertSee('10')
            ->assertSee('support@example.test')
            ->assertDontSee('Hidden Brand');
    }

    public function test_public_catalog_is_not_exposed_without_web_channel_or_when_discovery_is_disabled(): void
    {
        $workspace = Workspace::factory()->create([
            'type' => 'company',
            'slug' => 'private-catalog-workspace',
        ]);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->publish($roots->forWorkspace($workspace));

        $this->get('/catalog/'.$workspace->slug.'/'.$catalog->slug)->assertNotFound();

        $catalog = $roots->updateSettings($catalog, [
            'channel_visibility' => ['web' => true],
            'discovery_settings' => ['discoverable' => false],
        ]);
        $this->get('/catalog/'.$workspace->slug.'/'.$catalog->slug)->assertNotFound();
    }

    public function test_public_category_selection_uses_category_visibility_localization_and_seo(): void
    {
        $workspace = Workspace::factory()->create([
            'type' => 'company',
            'slug' => 'category-catalog-workspace',
        ]);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->updateSettings($roots->forWorkspace($workspace), [
            'channel_visibility' => ['web' => true],
            'default_language' => 'en',
            'default_locale' => 'en_US',
            'rtl_mode' => false,
        ]);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $service = app(ChatCatalogService::class);
            $category = $service->createCategory($workspace, [
                'name' => 'Services',
                'slug' => 'services',
                'description' => 'Default description',
                'localization' => [
                    'en' => ['name' => 'Localized Services', 'description' => 'Localized description'],
                ],
                'seo' => [
                    'title' => 'Services SEO Title',
                    'canonical_slug' => 'services',
                ],
            ]);
            $service->createItem($workspace, [
                'name' => 'Service Item',
                'price' => '10.00',
                'chat_catalog_category_id' => $category->id,
            ]);
            $service->createItem($workspace, [
                'name' => 'Uncategorized Item',
                'price' => '10.00',
            ]);
        });
        $catalog = $roots->publish($catalog);

        $this->get('/catalog/'.$workspace->slug.'/'.$catalog->slug.'?category=services')
            ->assertOk()
            ->assertSee('Services SEO Title')
            ->assertSee('Localized Services')
            ->assertSee('Service Item')
            ->assertDontSee('Uncategorized Item');
    }
}
