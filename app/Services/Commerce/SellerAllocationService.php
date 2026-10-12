<?php

namespace App\Services\Commerce;

use App\Enums\Commerce\CommerceSettlementStatus;
use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Audit\AuditLogService;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates reconciliation allocations only — not wallet balances or HyperPay settlement execution.
 */
final class SellerAllocationService
{
    public function __construct(
        private readonly CommerceSellerOwnershipGuard $ownershipGuard,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array{gross_amount: string|int, platform_fee_amount?: string|int, fee_type?: string, fee_metadata?: array<string,mixed>|null, currency: string, order_item_id?: int|null, payment_id?: int|null}  $data
     */
    public function createForOrderLine(
        Order $order,
        Seller $seller,
        array $data,
        ?OrderItem $orderItem = null,
    ): SellerAllocation {
        $workspaceId = (int) $order->workspace_id;
        $this->ownershipGuard->assertSellerInWorkspace($workspaceId, (int) $seller->id);
        $this->ownershipGuard->assertOrderSellerConsistency($order, $seller);

        $orderItemId = $orderItem?->id ?? ($data['order_item_id'] ?? null);
        if ($orderItem === null && $orderItemId !== null) {
            $orderItem = OrderItem::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('order_id', $order->id)
                ->whereKey($orderItemId)
                ->first();
            if (! $orderItem) {
                throw new RuntimeException('Order item does not belong to the order.');
            }
        }
        if ($orderItem !== null) {
            $this->ownershipGuard->assertOrderItemSellerConsistency($orderItem, $seller);
            if ((int) $orderItem->order_id !== (int) $order->id) {
                throw new RuntimeException('Order item does not belong to the order.');
            }
        }

        $currency = strtoupper((string) ($data['currency'] ?? $order->currency ?? 'USD'));
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter code.');
        }
        if (strtoupper((string) $order->currency) !== $currency) {
            throw new InvalidArgumentException('Allocation currency must match the order currency.');
        }

        $gross = Money::of($data['gross_amount']);
        $platformFee = Money::of($data['platform_fee_amount'] ?? '0');
        $sellerNet = Money::sub($gross, $platformFee);

        if (Money::cmp($sellerNet, '0') < 0) {
            throw new InvalidArgumentException('Seller net amount cannot be negative.');
        }

        $paymentId = isset($data['payment_id']) ? (int) $data['payment_id'] : null;
        if ($paymentId !== null) {
            $payment = Payment::withoutGlobalScopes()
                ->whereKey($paymentId)
                ->where('workspace_id', $workspaceId)
                ->first();
            if (! $payment) {
                throw new RuntimeException('Payment not found in workspace.');
            }
            $this->ownershipGuard->assertPaymentBelongsToOrder($payment, $order);
        }

        if ($orderItemId !== null) {
            $existing = SellerAllocation::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('order_item_id', $orderItemId)
                ->where('commerce_seller_id', $seller->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            $allocation = SellerAllocation::query()->create([
                'workspace_id' => $workspaceId,
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'order_item_id' => $orderItemId,
                'commerce_seller_id' => $seller->id,
                'gross_amount' => $gross,
                'platform_fee_amount' => $platformFee,
                'fee_type' => $data['fee_type'] ?? 'none',
                'fee_metadata' => $data['fee_metadata'] ?? null,
                'seller_net_amount' => $sellerNet,
                'currency' => $currency,
                'settlement_status' => CommerceSettlementStatus::Pending,
                'provider_settlement_reference' => null,
                'metadata' => null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($orderItemId === null) {
                throw $exception;
            }

            return SellerAllocation::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('order_item_id', $orderItemId)
                ->where('commerce_seller_id', $seller->id)
                ->firstOrFail();
        }

        $this->audit->log(
            action: 'commerce.seller_allocation.created',
            entityType: SellerAllocation::class,
            entityId: (int) $allocation->id,
            meta: [
                'order_id' => (int) $order->id,
                'order_item_id' => $orderItemId !== null ? (int) $orderItemId : null,
                'seller_id' => (int) $seller->id,
                'gross_amount' => $gross,
                'currency' => $currency,
            ],
            workspaceId: $workspaceId,
        );

        return $allocation;
    }
}
