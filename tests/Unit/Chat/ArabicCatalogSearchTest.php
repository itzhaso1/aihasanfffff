<?php

namespace Tests\Unit\Chat;

use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Chat\ArabicSearchTokenExpander;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArabicCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_plural_query_finds_singular_dress_name(): void
    {
        $workspace = $this->publishedDressWorkspace();
        $rows = app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'هل يوجد فساتين؟');

        $this->assertSame(['فستان سهرة أسود'], collect($rows)->pluck('name')->all());
    }

    #[DataProvider('matchingQueries')]
    public function test_related_arabic_queries_match_dress(string $query): void
    {
        $workspace = $this->publishedDressWorkspace();
        $names = collect(app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, $query))->pluck('name');

        $this->assertTrue($names->contains('فستان سهرة أسود'), $query);
    }

    #[DataProvider('unrelatedQueries')]
    public function test_unrelated_queries_do_not_match_dress(string $query): void
    {
        $workspace = $this->publishedDressWorkspace();
        $names = collect(app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, $query))->pluck('name');

        $this->assertFalse($names->contains('فستان سهرة أسود'), $query);
    }

    public function test_generic_product_question_lists_published_catalog_items(): void
    {
        $workspace = $this->publishedDressWorkspace();
        $names = collect(app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'وش عندكم منتجات؟'))->pluck('name');

        $this->assertTrue($names->contains('فستان سهرة أسود'));
    }

    public function test_generic_services_question_lists_service_items_only(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'تنظيف منزل',
                'price' => '120.00',
                'currency' => 'SAR',
                'item_kind' => 'service',
            ]);
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'فستان سهرة أسود',
                'price' => '250.00',
                'currency' => 'SAR',
                'item_kind' => 'product',
            ]);
        });
        $roots->publish($roots->forWorkspace($workspace));

        $names = collect(app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'وش الخدمات اللي تقدمونها؟'))->pluck('name');

        $this->assertSame(['تنظيف منزل'], $names->all());
    }

    public function test_published_catalog_with_no_items_returns_empty_for_browse_query(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        $roots->publish($roots->forWorkspace($workspace));

        $rows = app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'ممكن تعرض لي منتجاتكم؟');

        $this->assertSame([], $rows);
    }

    public function test_retrieval_does_not_leak_items_from_other_workspaces(): void
    {
        $first = $this->publishedDressWorkspace();
        $second = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        $roots->publish($roots->forWorkspace($second));

        $names = collect(app(ChatCatalogRetrievalService::class)->searchForPrompt($second, 'وش عندكم منتجات؟'))->pluck('name');

        $this->assertFalse($names->contains('فستان سهرة أسود'));
        $this->assertSame($first->id, $first->fresh()->id);
    }

    public function test_ai_search_returns_items_when_storefront_catalog_is_draft(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        app(ChatCatalogRootService::class)->forWorkspace($workspace);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'فستان سهرة أسود',
                'price' => '250.00',
                'currency' => 'SAR',
            ]);
        });

        $this->assertSame([], app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'فساتين'));
        $this->assertSame(
            ['فستان سهرة أسود'],
            collect(app(ChatCatalogRetrievalService::class)->searchForAi($workspace, 'فساتين'))->pluck('name')->all(),
        );
    }

    public function test_ai_search_lists_services_for_generic_question_on_draft_catalog(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'تنظيف منزل',
                'price' => '120.00',
                'currency' => 'SAR',
                'item_kind' => 'service',
            ]);
        });
        $roots->draft($roots->forWorkspace($workspace));

        $this->assertSame(
            [],
            collect(app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'شو الخدمات المتوفرة عندكم؟'))->pluck('name')->all(),
        );
        $this->assertSame(
            ['تنظيف منزل'],
            collect(app(ChatCatalogRetrievalService::class)->searchForAi($workspace, 'شو الخدمات المتوفرة عندكم؟'))->pluck('name')->all(),
        );
    }

    public function test_draft_catalog_hides_published_item_from_retrieval(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        app(ChatCatalogRootService::class)->forWorkspace($workspace);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'فستان سهرة أسود',
                'price' => '250.00',
                'currency' => 'SAR',
            ]);
        });

        $this->assertSame([], app(ChatCatalogRetrievalService::class)->searchForPrompt($workspace, 'فساتين'));
    }

    public function test_expander_maps_fesatin_to_fustan(): void
    {
        $variants = app(ArabicSearchTokenExpander::class)->expand('فساتين');

        $this->assertContains('فستان', $variants);
        $this->assertLessThanOrEqual(ArabicSearchTokenExpander::MAX_VARIANTS_PER_TOKEN, count($variants));
    }

    public function test_expander_maps_arabic_and_excel_spellings(): void
    {
        $expander = app(ArabicSearchTokenExpander::class);

        $this->assertContains('عربي', $expander->expand('عربيه'));
        $this->assertContains('عربي', $expander->expand('عربية'));
        $this->assertContains('excel', $expander->expand('اكسل'));
    }

    public static function matchingQueries(): array
    {
        return [
            ['فستان'],
            ['فساتين'],
            ['فستان سهرة'],
            ['فساتين سهرة'],
            ['أبي فستان سهرة'],
            ['أبغى فستان أسود'],
            ['عندكم فساتين'],
            ['فيه فساتين'],
            ['وش عندكم من الفساتين'],
        ];
    }

    public static function unrelatedQueries(): array
    {
        return [
            ['أحذية'],
            ['عبايات'],
            ['هواتف'],
        ];
    }

    private function publishedDressWorkspace(): Workspace
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'فستان سهرة أسود',
                'price' => '250.00',
                'currency' => 'SAR',
            ]);
        });
        $roots->publish($roots->forWorkspace($workspace));

        return $workspace;
    }
}
