<?php

namespace Tests\Unit\Commerce;

use App\Enums\Commerce\CommerceOperatingMode;
use App\Enums\Commerce\CommerceSettlementStatus;
use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerPaymentAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Commerce\CommerceSellerOwnershipGuard;
use App\Services\Commerce\CommerceWorkspaceProfileService;
use App\Services\Commerce\SellerAllocationService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CommerceSellerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_creates_inside_workspace_with_unique_slug(): void
    {
        $workspace = Workspace::factory()->create();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            Seller::factory()->create(['workspace_id' => $workspace->id, 'slug' => 'main-store']);
            $this->expectException(QueryException::class);
            Seller::factory()->create(['workspace_id' => $workspace->id, 'slug' => 'main-store']);
        });
    }

    public function test_seller_cannot_cross_workspace_via_guard(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();
        $sellerB = Seller::factory()->create(['workspace_id' => $workspaceB->id]);

        $guard = app(CommerceSellerOwnershipGuard::class);

        $this->expectException(\RuntimeException::class);
        app(WorkspaceContext::class)->runFor($workspaceA, fn () => $guard->assertSellerInWorkspace(
            (int) $workspaceA->id,
            (int) $sellerB->id,
        ));
    }

    public function test_payment_account_belongs_to_seller_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $account = SellerPaymentAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'commerce_seller_id' => $seller->id,
            'metadata' => ['api_key' => 'secret-should-not-leak'],
        ]);

        $this->assertSame((int) $workspace->id, (int) $account->workspace_id);
        $this->assertSame((int) $seller->id, (int) $account->commerce_seller_id);

        $guard = app(CommerceSellerOwnershipGuard::class);
        $guard->assertPaymentAccountInWorkspace((int) $workspace->id, $account);

        $other = Workspace::factory()->create();
        $this->expectException(\RuntimeException::class);
        $guard->assertPaymentAccountInWorkspace((int) $other->id, $account);
    }

    public function test_allocation_uses_money_not_float_and_separates_settlement_from_payment(): void
    {
        $workspace = Workspace::factory()->create();
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $order = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'payment_status' => 'pending',
            'currency' => 'SAR',
        ]);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($order, $seller): void {
            $allocation = app(SellerAllocationService::class)->createForOrderLine($order, $seller, [
                'gross_amount' => '100.00',
                'platform_fee_amount' => '15.50',
                'currency' => 'SAR',
            ]);

            $this->assertSame('84.50', $allocation->seller_net_amount);
            $this->assertSame(CommerceSettlementStatus::Pending, $allocation->settlement_status);
            $this->assertSame('pending', $order->fresh()->payment_status);
        });
    }

    public function test_allocation_rejects_cross_workspace_seller(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();
        $sellerB = Seller::factory()->create(['workspace_id' => $workspaceB->id]);
        $orderA = Order::factory()->create(['workspace_id' => $workspaceA->id]);

        $this->expectException(\RuntimeException::class);

        app(WorkspaceContext::class)->runFor($workspaceA, fn () => app(SellerAllocationService::class)->createForOrderLine(
            $orderA,
            $sellerB,
            ['gross_amount' => '10', 'currency' => 'SAR'],
        ));
    }

    public function test_allocation_rejects_a_seller_that_does_not_own_the_commerce_line(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $otherSeller = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $order = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'commerce',
            'currency' => 'SAR',
        ]);

        $line = app(WorkspaceContext::class)->runFor($workspace, fn () => OrderItem::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'commerce_seller_id' => $owner->id,
            'product_name' => 'Catalog item',
            'quantity' => 1,
            'unit_price' => '10.00',
            'discount_amount' => '0.00',
            'total_amount' => '10.00',
        ]));

        $this->expectException(\RuntimeException::class);

        app(WorkspaceContext::class)->runFor($workspace, fn () => app(SellerAllocationService::class)->createForOrderLine(
            $order,
            $otherSeller,
            ['gross_amount' => '10.00', 'currency' => 'SAR'],
            $line,
        ));
    }

    public function test_allocation_rejects_a_forged_order_item_id(): void
    {
        $workspace = Workspace::factory()->create();
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $targetOrder = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'commerce',
            'currency' => 'SAR',
        ]);
        $otherOrder = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'commerce',
            'currency' => 'SAR',
        ]);
        $line = app(WorkspaceContext::class)->runFor($workspace, fn () => OrderItem::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $otherOrder->id,
            'commerce_seller_id' => $seller->id,
            'product_name' => 'Other order catalog item',
            'quantity' => 1,
            'unit_price' => '10.00',
            'discount_amount' => '0.00',
            'total_amount' => '10.00',
        ]));

        $this->expectException(\RuntimeException::class);

        app(WorkspaceContext::class)->runFor($workspace, fn () => app(SellerAllocationService::class)->createForOrderLine(
            $targetOrder,
            $seller,
            [
                'gross_amount' => '10.00',
                'currency' => 'SAR',
                'order_item_id' => $line->id,
            ],
        ));
    }

    public function test_untrusted_seller_id_rejected_by_ownership_guard(): void
    {
        $workspace = Workspace::factory()->create();
        $foreignSeller = Seller::factory()->create();

        $this->expectException(\RuntimeException::class);
        app(CommerceSellerOwnershipGuard::class)->assertSellerInWorkspace(
            (int) $workspace->id,
            (int) $foreignSeller->id,
        );
    }

    public function test_payment_metadata_does_not_imply_paid_order(): void
    {
        $workspace = Workspace::factory()->create();
        $order = Order::factory()->create([
            'workspace_id' => $workspace->id,
            'payment_status' => 'pending',
        ]);
        $payment = Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'status' => 'pending',
            'provider_payload' => ['status' => 'paid', 'customer_claim' => true],
        ]);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending', $payment->status);
    }

    public function test_commerce_operating_mode_from_profile_service(): void
    {
        $workspace = Workspace::factory()->create();
        $service = app(CommerceWorkspaceProfileService::class);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $service): void {
            $this->assertSame(CommerceOperatingMode::IndependentStore, $service->operatingMode($workspace));

            $service->ensureProfile($workspace, CommerceOperatingMode::Marketplace);
            $this->assertSame(CommerceOperatingMode::Marketplace, $service->operatingMode($workspace));
        });
    }

    public function test_seller_policy_requires_workspace_membership(): void
    {
        $workspace = Workspace::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace->users()->attach($member->id, ['membership_role' => 'manager', 'status' => 'active']);

        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($member, $outsider, $seller): void {
            $this->assertTrue(Gate::forUser($member)->allows('update', $seller));
            $this->assertFalse(Gate::forUser($outsider)->allows('view', $seller));
        });
    }
}
