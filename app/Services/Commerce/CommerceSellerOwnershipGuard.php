<?php

namespace App\Services\Commerce;

use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerPaymentAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use RuntimeException;

/**
 * Validates trusted domain relationships for Commerce sellers (never trust AI/client IDs alone).
 */
final class CommerceSellerOwnershipGuard
{
    public function assertSellerInWorkspace(int $workspaceId, int $sellerId): Seller
    {
        $seller = Seller::withoutGlobalScopes()
            ->whereKey($sellerId)
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $seller) {
            throw new RuntimeException('Seller does not belong to the current workspace.');
        }

        return $seller;
    }

    public function assertPaymentAccountInWorkspace(int $workspaceId, SellerPaymentAccount $account): void
    {
        if ((int) $account->workspace_id !== $workspaceId) {
            throw new RuntimeException('Payment account workspace mismatch.');
        }

        $this->assertSellerInWorkspace($workspaceId, (int) $account->commerce_seller_id);
    }

    public function assertOrderSellerConsistency(Order $order, ?Seller $seller): void
    {
        if ($seller === null) {
            return;
        }

        if ((int) $order->workspace_id !== (int) $seller->workspace_id) {
            throw new RuntimeException('Order seller workspace mismatch.');
        }
    }

    public function assertOrderItemSellerConsistency(OrderItem $item, Seller $seller): void
    {
        if ((int) $item->workspace_id !== (int) $seller->workspace_id) {
            throw new RuntimeException('Order item seller workspace mismatch.');
        }
        if ($item->commerce_seller_id !== null
            && (int) $item->commerce_seller_id !== (int) $seller->id) {
            throw new RuntimeException('Seller does not own the order item.');
        }

        $order = $item->order;
        if ($order && (int) $order->workspace_id !== (int) $seller->workspace_id) {
            throw new RuntimeException('Order item order workspace mismatch.');
        }
        if ($order?->source === 'commerce' && $item->commerce_seller_id === null) {
            throw new RuntimeException('Commerce order items require a seller.');
        }
    }

    public function assertPaymentBelongsToOrder(Payment $payment, Order $order): void
    {
        if ((int) $payment->workspace_id !== (int) $order->workspace_id) {
            throw new RuntimeException('Payment order workspace mismatch.');
        }

        if ($payment->order_id !== null && (int) $payment->order_id !== (int) $order->id) {
            throw new RuntimeException('Payment does not belong to the order.');
        }
    }
}
