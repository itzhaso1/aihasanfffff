<?php

namespace Tests\Unit\Chat;

use App\Support\Chat\CatalogBrowseQueryDetector;
use App\Support\Chat\GulfArabicInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('generalAvailabilityQuestions')]
    public function test_request_verbs_do_not_hide_a_general_availability_question(string $query): void
    {
        $tokens = app(GulfArabicInterpreter::class)->interpret($query)['search_tokens'];

        $this->assertTrue($this->detector->isBrowseCatalogQuery($query, $tokens), $query);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function generalAvailabilityQuestions(): array
    {
        return [
            'tell me what is available' => ['قولي إيش متوفر عندك طيب'],
            'what is available' => ['وش متوفر عندكم؟'],
            'what do you have' => ['إيش عندكم؟'],
            'i want to see the products' => ['أبغى أشوف المنتجات'],
            'show me what is available' => ['ورني المتوفر'],
            'what products are available' => ['ما هي المنتجات المتاحة؟'],
        ];
    }

    public function test_a_named_product_question_stays_specific(): void
    {
        $query = 'قولي عن الفستان الأسود';
        $tokens = app(GulfArabicInterpreter::class)->interpret($query)['search_tokens'];

        $this->assertFalse($this->detector->isBrowseCatalogQuery($query, $tokens));
    }

    public function test_levantine_available_services_question_is_browse(): void
    {
        $query = 'شو الخدمات المتوفرة عندكم؟';
        $tokens = app(GulfArabicInterpreter::class)->interpret($query)['search_tokens'];

        $this->assertTrue($this->detector->isBrowseCatalogQuery($query, $tokens));
        $this->assertSame('service', $this->detector->browseItemKindFilter($query, $tokens));
    }
}
