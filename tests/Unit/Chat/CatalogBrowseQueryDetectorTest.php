<?php

namespace Tests\Unit\Chat;

use App\Support\Chat\CatalogBrowseQueryDetector;
use App\Support\Chat\GulfArabicInterpreter;
use Tests\TestCase;

class CatalogBrowseQueryDetectorTest extends TestCase
{
    private CatalogBrowseQueryDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(CatalogBrowseQueryDetector::class);
    }

    public function test_detects_generic_arabic_product_listing(): void
    {
        $gulf = app(GulfArabicInterpreter::class);
        $interpret = $gulf->interpret('وش عندكم منتجات؟');

        $this->assertTrue($this->detector->isBrowseCatalogQuery('وش عندكم منتجات؟', $interpret['search_tokens']));
    }

    public function test_specific_product_name_is_not_browse_only(): void
    {
        $tokens = app(GulfArabicInterpreter::class)->interpret('هل عندكم فستان أسود؟')['search_tokens'];

        $this->assertFalse($this->detector->isBrowseCatalogQuery('هل عندكم فستان أسود؟', $tokens));
    }

    public function test_service_filter_from_arabic_services_question(): void
    {
        $gulf = app(GulfArabicInterpreter::class);
        $interpret = $gulf->interpret('وش الخدمات عندكم؟');

        $this->assertSame('service', $this->detector->browseItemKindFilter('وش الخدمات عندكم؟', $interpret['search_tokens']));
    }

    public function test_levantine_available_services_question_is_browse(): void
    {
        $query = 'شو الخدمات المتوفرة عندكم؟';
        $tokens = app(GulfArabicInterpreter::class)->interpret($query)['search_tokens'];

        $this->assertTrue($this->detector->isBrowseCatalogQuery($query, $tokens));
        $this->assertSame('service', $this->detector->browseItemKindFilter($query, $tokens));
    }
}
