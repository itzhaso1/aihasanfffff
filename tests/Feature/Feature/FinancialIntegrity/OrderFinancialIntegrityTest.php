<?php

namespace Tests\Feature\Feature\FinancialIntegrity;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Order\OrderService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_totals_match_line_sum_discount_and_shipping(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '0500000001',
        ]);
        $productA = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Item A',
            'slug' => 'item-a',
            'sku' => 'A-1',
            'price' => '10.33',
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);
        $productB = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Item B',
            'slug' => 'item-b',
            'sku' => 'B-1',
            'price' => '0.05',
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);

        $order = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $customer, $productA, $productB) {
            return app(OrderService::class)->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'discount_amount' => '0.01',
                'shipping_amount' => '1.00',
                'items' => [
                    ['product_id' => $productA->id, 'quantity' => 3, 'discount_amount' => '0.02'],
                    ['product_id' => $productB->id, 'quantity' => 2],
                ],
            ]);
        });

        $lineSum = '0.00';
        foreach ($order->items as $item) {
            $lineSum = Money::add($lineSum, (string) $item->total_amount);
        }

        $expectedTotal = Money::add(
            Money::sub($lineSum, Money::round((string) $order->discount_amount)),
            Money::round((string) $order->shipping_amount),
        );

        $this->assertSame(0, Money::cmp($lineSum, (string) $order->subtotal));
        $this->assertSame(0, Money::cmp($expectedTotal, (string) $order->total_amount));
        $this->assertSame(0, Money::cmp('31.07', (string) $order->subtotal));
        $this->assertSame(0, Money::cmp('32.06', (string) $order->total_amount));

        $customer = Customer::withoutGlobalScopes()->findOrFail($customer->id);
        $this->assertSame(1, $customer->orders_count);
        $this->assertSame(0, Money::cmp('32.06', (string) $customer->total_purchases));
    }

    public function test_cancelled_order_refreshes_customer_aggregates(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '0500000002',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Item',
            'slug' => 'item-c',
            'sku' => 'C-1',
            'price' => '20.00',
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);

        $order = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $customer, $product) {
            return app(OrderService::class)->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]);
        });

        app(WorkspaceContext::class)->runFor($workspace, function () use ($order): void {
            app(OrderService::class)->cancel($order);
        });

        $customer = Customer::withoutGlobalScopes()->findOrFail($customer->id);
        $this->assertSame(1, $customer->orders_count);
        $this->assertSame(0, Money::cmp('20.00', (string) $customer->total_purchases));
    }
}
