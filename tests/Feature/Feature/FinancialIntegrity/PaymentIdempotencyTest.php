<?php

namespace Tests\Feature\Feature\FinancialIntegrity;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Workspace;
use App\Services\Payment\PaymentService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_payment_link_calls_reuse_same_payment_and_idempotency_key(): void
    {
        $workspace = Workspace::factory()->create();
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-IDEM-00000001',
            'source' => 'manual',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50,
            'placed_at' => now(),
        ]);

        $expectedKey = PaymentService::orderCheckoutIdempotencyKey($workspace->id, $order->id);

        $first = app(WorkspaceContext::class)->runFor($workspace, fn () => app(PaymentService::class)->createPaymentLink($order, null, 'local_sandbox'));
        $second = app(WorkspaceContext::class)->runFor($workspace, fn () => app(PaymentService::class)->createPaymentLink($order->fresh(), null, 'local_sandbox'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($expectedKey, $first->idempotency_key);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('order_id', $order->id)->count());
    }
}
