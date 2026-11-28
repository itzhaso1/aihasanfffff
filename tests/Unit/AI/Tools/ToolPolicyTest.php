<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\EmployeeToolGrant;
use App\AI\Tools\ToolDenial;
use App\AI\Tools\ToolPolicy;
use PHPUnit\Framework\TestCase;

class ToolPolicyTest extends TestCase
{
    public function test_no_skills_and_no_permissions_offer_nothing(): void
    {
        $this->assertSame([], $this->offered(AiRunMode::Live, [], []));
    }

    public function test_sales_skill_offers_catalog_tools_and_sandbox_blocks_writes(): void
    {
        $this->assertSame(
            [
                CanonicalTools::CANCEL_BOOKING,
                CanonicalTools::CANCEL_CUSTOMER_ORDER,
                CanonicalTools::CHECK_BOOKING_AVAILABILITY,
                CanonicalTools::CHECK_PRODUCT_AVAILABILITY,
                CanonicalTools::COMPARE_PRODUCTS,
                CanonicalTools::CREATE_BOOKING,
                CanonicalTools::CREATE_CUSTOMER_ORDER,
                CanonicalTools::EVALUATE_PRODUCT_SUITABILITY,
                CanonicalTools::GET_ACTIVE_OFFERS,
                CanonicalTools::GET_BOOKING_OPTIONS,
                CanonicalTools::GET_CURRENT_PRICE,
                CanonicalTools::GET_CUSTOMER_BOOKINGS,
                CanonicalTools::GET_ORDER_STATUS,
                CanonicalTools::GET_PRODUCT_DETAILS,
                CanonicalTools::GET_PRODUCT_INTELLIGENCE,
                CanonicalTools::GET_PRODUCT_VARIANTS,
                CanonicalTools::GET_SHIPPING_INFORMATION,
                CanonicalTools::GET_STORE_INFORMATION,
                CanonicalTools::REQUEST_LOCATION_CAPTURE,
                CanonicalTools::RESCHEDULE_BOOKING,
                CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT,
                CanonicalTools::SEARCH_PRODUCTS,
                CanonicalTools::SEND_PRODUCT_IMAGE,
                CanonicalTools::TRANSFER_TO_HUMAN_AGENT,
                CanonicalTools::UPDATE_BOOKING,
                CanonicalTools::UPDATE_CUSTOMER_ORDER,
            ],
            $this->offered(AiRunMode::Live, ['sales'], []),
        );
        $this->assertSame(
            [
                CanonicalTools::CHECK_BOOKING_AVAILABILITY,
                CanonicalTools::CHECK_PRODUCT_AVAILABILITY,
                CanonicalTools::COMPARE_PRODUCTS,
                CanonicalTools::EVALUATE_PRODUCT_SUITABILITY,
                CanonicalTools::GET_ACTIVE_OFFERS,
                CanonicalTools::GET_BOOKING_OPTIONS,
                CanonicalTools::GET_CURRENT_PRICE,
                CanonicalTools::GET_CUSTOMER_BOOKINGS,
                CanonicalTools::GET_ORDER_STATUS,
                CanonicalTools::GET_PRODUCT_DETAILS,
                CanonicalTools::GET_PRODUCT_INTELLIGENCE,
                CanonicalTools::GET_PRODUCT_VARIANTS,
                CanonicalTools::GET_SHIPPING_INFORMATION,
                CanonicalTools::GET_STORE_INFORMATION,
                CanonicalTools::SEARCH_PRODUCTS,
            ],
            $this->offered(AiRunMode::Preview, ['sales'], []),
        );
    }

    public function test_product_search_skill_offers_only_search(): void
    {
        $this->assertSame(
            [CanonicalTools::SEARCH_PRODUCTS],
            $this->offered(AiRunMode::Live, ['product_search'], []),
        );
    }

    public function test_explicit_deny_wins_over_skill_allowance(): void
    {
        $this->assertSame(
            [
                CanonicalTools::CHECK_BOOKING_AVAILABILITY,
                CanonicalTools::CHECK_PRODUCT_AVAILABILITY,
                CanonicalTools::COMPARE_PRODUCTS,
                CanonicalTools::GET_ACTIVE_OFFERS,
                CanonicalTools::GET_BOOKING_OPTIONS,
                CanonicalTools::GET_CURRENT_PRICE,
                CanonicalTools::GET_CUSTOMER_BOOKINGS,
                CanonicalTools::GET_ORDER_STATUS,
                CanonicalTools::GET_PRODUCT_DETAILS,
                CanonicalTools::GET_PRODUCT_INTELLIGENCE,
                CanonicalTools::GET_PRODUCT_VARIANTS,
                CanonicalTools::GET_SHIPPING_INFORMATION,
                CanonicalTools::GET_STORE_INFORMATION,
            ],
            $this->offered(AiRunMode::Preview, ['customer_support'], [
                new EmployeeToolGrant(CanonicalTools::SEARCH_PRODUCTS, false),
            ]),
        );
    }

    public function test_permissions_only_employee_can_receive_an_explicit_allow(): void
    {
        $this->assertSame(
            [CanonicalTools::SEARCH_PRODUCTS],
            $this->offered(AiRunMode::Live, [], [
                new EmployeeToolGrant(CanonicalTools::SEARCH_PRODUCTS, true),
            ]),
        );
    }

    public function test_faq_skill_does_not_unlock_catalog_tools(): void
    {
        $this->assertSame([], $this->offered(AiRunMode::Live, ['faq'], []));
    }

    public function test_save_is_denied_in_sandbox_even_when_skill_allows_it(): void
    {
        $decision = (new ToolPolicy)->authorize(
            $this->request(AiRunMode::Evaluation),
            new ResolvedEmployee(3, skillKeys: ['sales']),
            CanonicalTools::saveCustomerCommerceContext(),
            [],
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(ToolDenial::SANDBOX_WRITE, $decision->denial);
    }

    /**
     * @param  list<string>  $skills
     * @param  list<EmployeeToolGrant>  $grants
     * @return list<string>
     */
    private function offered(AiRunMode $mode, array $skills, array $grants): array
    {
        return (new ToolPolicy)->offeredNames(
            $this->request($mode),
            new ResolvedEmployee(3, skillKeys: $skills),
            CanonicalTools::all(),
            $grants,
        );
    }

    private function request(AiRunMode $mode): AiRequest
    {
        return new AiRequest(
            workspaceId: 9,
            conversationId: $mode === AiRunMode::Live ? 4 : null,
            employeeId: 3,
            message: new AiInboundMessage('كم'),
            runMode: $mode,
        );
    }
}
