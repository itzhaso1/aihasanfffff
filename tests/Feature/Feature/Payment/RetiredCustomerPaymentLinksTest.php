<?php

namespace Tests\Feature\Feature\Payment;

use App\Jobs\CreatePaymentLink;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\PaymentService;
use App\Support\Commerce\RetiredCustomerCheckout;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetiredCustomerPaymentLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_create_payment_link_is_disabled_for_customer_orders(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-RETIRE-1',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 40,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 40,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(RetiredCustomerCheckout::PAYMENT_LINKS_MESSAGE);
        app(PaymentService::class)->createPaymentLink($order);
    }

    public function test_workspace_payment_gateway_pages_are_gone(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.payment-gateways.index'))
            ->assertStatus(410);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.payments.create'))
            ->assertStatus(410);
    }

    public function test_payments_index_stays_as_history_without_create_link(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.payments.index'))
            ->assertOk()
            ->assertDontSee('إنشاء رابط دفع', false)
            ->assertDontSee(route('workspace.payment-gateways.index'), false)
            ->assertDontSee(route('workspace.payments.create'), false);
    }

    public function test_sidebar_keeps_subscriptions_and_drops_payment_gateways(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.dashboard'))
            ->assertOk()
            ->assertDontSee(route('workspace.payment-gateways.index'), false)
            ->assertSee(route('workspace.subscriptions.index'), false)
            ->assertSee(route('workspace.payments.index'), false);
    }

    public function test_queued_payment_link_job_is_a_noop(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-RETIRE-JOB',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 10,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10,
        ]);

        (new CreatePaymentLink($order->id))->handle(app(PaymentService::class));

        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
