<?php

namespace Database\Factories\Commerce;

use App\Enums\Commerce\CommerceSettlementStatus;
use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerAllocation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerAllocation> */
class SellerAllocationFactory extends Factory
{
    protected $model = SellerAllocation::class;

    public function definition(): array
    {
        return [
            'workspace_id' => fn (array $attributes) => Order::withoutGlobalScopes()
                ->find($attributes['order_id'] ?? null)?->workspace_id
                ?? Order::factory()->create()->workspace_id,
            'order_id' => Order::factory(),
            'payment_id' => null,
            'order_item_id' => null,
            'commerce_seller_id' => Seller::factory(),
            'gross_amount' => '100.00',
            'platform_fee_amount' => '10.00',
            'seller_net_amount' => '90.00',
            'currency' => 'SAR',
            'settlement_status' => CommerceSettlementStatus::Pending,
            'provider_settlement_reference' => null,
            'metadata' => null,
        ];
    }
}
