<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Money\Money;
use DateTimeInterface;

class CustomerAggregateService
{
    public function incrementOrderAggregates(
        int $workspaceId,
        int $customerId,
        string $orderTotal,
        DateTimeInterface $lastOrderAt,
    ): void {
        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($customerId)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            return;
        }

        $customer->update([
            'orders_count' => (int) $customer->orders_count + 1,
            'total_purchases' => Money::add((string) $customer->total_purchases, Money::round($orderTotal)),
            'last_order_at' => $lastOrderAt,
        ]);
    }

    public function refreshOrderAggregates(int $workspaceId, int $customerId, ?DateTimeInterface $lastOrderAt = null): void
    {
        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($customerId)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            return;
        }

        $ordersQuery = Order::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('customer_id', $customerId);

        $customer->update([
            'orders_count' => (int) (clone $ordersQuery)->count(),
            'total_purchases' => Money::round((string) (clone $ordersQuery)->sum('total_amount')),
            'last_order_at' => $lastOrderAt ?? (clone $ordersQuery)->max('placed_at'),
        ]);
    }
}
