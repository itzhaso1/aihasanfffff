<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Guard;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\GuardOutcome;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\Checks\ActionClaimCheck;
use App\AI\Guard\Checks\PolicyCheck;
use App\AI\Guard\Checks\SecurityCheck;
use App\AI\Guard\Checks\StyleCheck;
use App\AI\Guard\Checks\TruthCheck;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Tools\CanonicalTools;
use PHPUnit\Framework\TestCase;

class HasimResponseGuardTest extends TestCase
{
    public function test_grounded_catalog_reply_passes(): void
    {
        $outcome = (new HasimResponseGuard)->review($this->input(
            'الفستان الأسود سعره 10.00 ريال',
            facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)],
        ));

        $this->assertSame(GuardVerdict::Pass, $outcome->verdict);
        $this->assertSame('الفستان الأسود سعره 10.00 ريال', $outcome->text);
        $this->assertSame(
            [SecurityCheck::NAME, TruthCheck::NAME, ActionClaimCheck::NAME, PolicyCheck::NAME, StyleCheck::NAME],
            array_map(static fn ($result): string => $result->guard, $outcome->results),
        );
    }

    public function test_invented_and_contradicting_prices_are_rejected(): void
    {
        $invented = (new HasimResponseGuard)->review($this->input('سعره 299 ريال'));
        $wrong = (new HasimResponseGuard)->review($this->input(
            'سعره 299 ريال',
            facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)],
        ));

        $this->assertSame(GuardVerdict::Rejected, $invented->verdict);
        $this->assertNull($invented->text);
        $this->assertSame('invented_price', $this->reason($invented, TruthCheck::NAME));
        $this->assertSame('ungrounded_price', $this->reason($wrong, TruthCheck::NAME));
    }

    public function test_availability_contradiction_is_rejected(): void
    {
        $outcome = (new HasimResponseGuard)->review($this->input(
            'المنتج متوفر الحين',
            facts: [new FactRecord('search_products', 'catalog_item:12.availability', 'out_of_stock')],
        ));

        $this->assertSame(GuardVerdict::Rejected, $outcome->verdict);
        $this->assertSame('availability_contradiction', $this->reason($outcome, TruthCheck::NAME));
    }

    public function test_product_availability_stock_and_currency_without_facts_are_rejected(): void
    {
        $guard = new HasimResponseGuard;
        $availability = $guard->review($this->input('المنتج متوفر الحين'));
        $stock = $guard->review($this->input('الكمية 4 قطع'));
        $currency = $guard->review($this->input('العملة USD'));
        $greeting = $guard->review($this->input('أنا موجود أساعدك'));

        $this->assertSame('ungrounded_availability', $this->reason($availability, TruthCheck::NAME));
        $this->assertSame('ungrounded_stock', $this->reason($stock, TruthCheck::NAME));
        $this->assertSame('ungrounded_currency', $this->reason($currency, TruthCheck::NAME));
        $this->assertSame(GuardVerdict::Pass, $greeting->verdict);
    }

    public function test_stock_and_currency_that_match_tool_facts_pass(): void
    {
        $outcome = (new HasimResponseGuard)->review($this->input(
            'المتوفر 4 قطع والعملة SAR',
            facts: [
                new FactRecord('search_products', 'catalog_item:12.stock', 4),
                new FactRecord('search_products', 'catalog_item:12.currency', 'SAR'),
            ],
        ));

        $this->assertSame(GuardVerdict::Pass, $outcome->verdict);
        $this->assertSame('المتوفر 4 قطع والعملة SAR', $outcome->text);
    }

    public function test_unconfirmed_save_is_rejected_and_confirmed_save_passes(): void
    {
        $denied = (new HasimResponseGuard)->review($this->input('حفظت القياس عندي'));
        $allowed = (new HasimResponseGuard)->review($this->input(
            'حفظت القياس عندي',
            actions: [new ConfirmedAction(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, '4')],
        ));

        $this->assertSame(GuardVerdict::Rejected, $denied->verdict);
        $this->assertSame('unconfirmed_action', $this->reason($denied, ActionClaimCheck::NAME));
        $this->assertSame(GuardVerdict::Pass, $allowed->verdict);
    }

    public function test_secrets_tool_leaks_and_finance_claims_are_rejected(): void
    {
        $guard = new HasimResponseGuard;
        $secret = $guard->review($this->input('token sk-abcdefghijklmnopqrstuv'));
        $leak = $guard->review($this->input('{"tool_call":{"name":"search_products"}}'));
        $finance = $guard->review($this->input('I refunded the invoice on the ledger'));

        $this->assertSame('secret_leak', $this->reason($secret, SecurityCheck::NAME));
        $this->assertSame('tool_or_identity_leak', $this->reason($leak, SecurityCheck::NAME));
        $this->assertSame('unconfirmed_action', $this->reason($finance, ActionClaimCheck::NAME));
        $this->assertNull($secret->text);
    }

    public function test_failure_markers_and_empty_replies_are_rejected(): void
    {
        $guard = new HasimResponseGuard;
        $marker = $guard->review($this->input('تعذر إنشاء الرد الآن'));
        $empty = $guard->review($this->input('   '));

        $this->assertSame('failure_marker', $this->reason($marker, PolicyCheck::NAME));
        $this->assertSame(GuardVerdict::Rejected, $empty->verdict);
        $this->assertSame('empty_output', $empty->results[0]->reasonCode);
    }

    public function test_style_and_markup_are_repaired_not_rejected(): void
    {
        $markup = (new HasimResponseGuard)->review($this->input('<b>هلا والله</b>'));
        $style = (new HasimResponseGuard)->review($this->input('مَرْحَبًا'));
        $truncated = (new HasimResponseGuard)->review($this->input(
            "ايه موجود\nسعره 10 ريال\nواذا ودك اعط",
            facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)],
            finish: ModelFinishReason::Length,
        ));

        $this->assertSame(GuardVerdict::Repaired, $markup->verdict);
        $this->assertSame('هلا والله', $markup->text);
        $this->assertSame('markup_stripped', $this->reason($markup, SecurityCheck::NAME));
        $this->assertSame(GuardVerdict::Repaired, $style->verdict);
        $this->assertSame('مرحبا', $style->text);
        $this->assertSame("ايه موجود\nسعره 10 ريال", $truncated->text);
        $this->assertSame('output_trimmed', $this->reason($truncated, StyleCheck::NAME));
    }

    /**
     * @param  list<FactRecord>  $facts
     * @param  list<ConfirmedAction>  $actions
     */
    private function input(
        string $text,
        array $facts = [],
        array $actions = [],
        ?ModelFinishReason $finish = null,
    ): GuardInput {
        return new GuardInput(
            request: new AiRequest(
                workspaceId: 9,
                conversationId: null,
                employeeId: 3,
                message: new AiInboundMessage('كم'),
                runMode: AiRunMode::Preview,
            ),
            text: $text,
            facts: $facts,
            confirmedActions: $actions,
            finishReason: $finish,
        );
    }

    private function reason(GuardOutcome $outcome, string $guard): ?string
    {
        foreach ($outcome->results as $result) {
            if ($result->guard === $guard) {
                return $result->reasonCode;
            }
        }

        return null;
    }
}
