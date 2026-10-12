<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Guard;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\Checks\TruthCheck;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\ResultSanitizer;
use PHPUnit\Framework\TestCase;

class TruthCheckOfferingTest extends TestCase
{
    public function test_test_mode_rejects_an_invented_service_list(): void
    {
        $finding = (new TruthCheck)->inspect(
            $this->input(AiRunMode::Preview, null),
            'عندنا خدمات تسويق، تصميم، وبرمجة',
        );

        $this->assertSame(GuardVerdict::Rejected, $finding->verdict);
        $this->assertSame('invented_offering', $finding->reasonCode);
    }

    public function test_missing_data_may_say_services_are_not_loaded(): void
    {
        $finding = (new TruthCheck)->inspect(
            $this->input(AiRunMode::Preview, null),
            'حاليا ما عندي خدمات مضافة',
        );

        $this->assertSame(GuardVerdict::Pass, $finding->verdict);
    }

    public function test_real_catalog_names_are_not_treated_as_invented(): void
    {
        $finding = (new TruthCheck)->inspect(
            $this->input(AiRunMode::Live, 21, [
                new FactRecord(CanonicalTools::SEARCH_PRODUCTS, 'result_status', 'available'),
                new FactRecord(CanonicalTools::SEARCH_PRODUCTS, 'catalog_item:4.name', 'تصميم'),
            ]),
            'عندنا تصميم',
        );

        $this->assertSame(GuardVerdict::Pass, $finding->verdict);
    }

    public function test_empty_search_is_explicit(): void
    {
        $clean = (new ResultSanitizer)->sanitize([
            'query' => 'خدمات',
            'count' => 0,
            'products' => [],
        ], CanonicalTools::SEARCH_PRODUCTS, true);

        $this->assertSame('empty', $clean['result_status']);
        $this->assertSame(0, $clean['count']);
    }

    private function input(AiRunMode $mode, ?int $conversationId, array $facts = []): GuardInput
    {
        return new GuardInput(
            request: new AiRequest(
                workspaceId: 11,
                conversationId: $conversationId,
                employeeId: 3,
                message: new AiInboundMessage('ايش عندك خدمات؟'),
                runMode: $mode,
            ),
            text: '',
            facts: $facts,
        );
    }
}
