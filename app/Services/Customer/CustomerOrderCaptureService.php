<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\CustomerStatus;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Workspace;
use App\Services\Order\WorkspaceOrderNumberGenerator;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerOrderCaptureService
{
    public function __construct(
        private readonly WorkspaceOrderNumberGenerator $orderNumbers,
        private readonly CustomerAggregateService $aggregateService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * Normalize customer phone number to consistent format.
     */
    public function normalizePhone(string $phone): string
    {
        $cleaned = trim($phone);
        // Replace Eastern Arabic numerals to standard digits
        $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $cleaned = str_replace($eastern, $western, $cleaned);

        // Strip any characters except digits and leading +
        $hasPlus = str_starts_with($cleaned, '+');
        $digits = preg_replace('/[^\d]/', '', $cleaned) ?? '';

        if ($hasPlus) {
            return '+'.$digits;
        }

        // Standardize Saudi local formats (05XXXXXXXX -> +9665XXXXXXXX)
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            return '+966'.substr($digits, 1);
        }

        if (str_starts_with($digits, '9665') && strlen($digits) === 12) {
            return '+'.$digits;
        }

        return $digits !== '' ? '+'.$digits : $phone;
    }

    /**
     * Automatically capture or update customer and record order from conversation.
     *
     * @param  array<int, array{chat_catalog_item_id?: int|null, product_name?: string|null, variant_id?: int|null, variant_name?: string|null, quantity?: int|null, unit_price?: float|string|null}>  $items
     * @return array{customer: Customer, order: Order, is_review_required: bool}
     */
    public function capture(
        Workspace $workspace,
        ?Conversation $conversation,
        string $customerName,
        string $phone,
        array $items,
        ?string $notes = null,
        ?string $address = null,
        bool $isComplete = true,
        ?string $reviewReason = null,
        ?string $requiredAction = null,
    ): array {
        $customerName = trim($customerName);
        if ($customerName === '') {
            $customerName = 'عميل جديد';
        }

        $normalizedPhone = $this->normalizePhone($phone);
        if ($normalizedPhone === '') {
            throw new InvalidArgumentException('Phone number is required to capture customer order.');
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $customerName,
            $normalizedPhone,
            $items,
            $notes,
            $address,
            $isComplete,
            $reviewReason,
            $requiredAction,
        ): array {
            return DB::transaction(function () use (
                $workspace,
                $conversation,
                $customerName,
                $normalizedPhone,
                $items,
                $notes,
                $address,
                $isComplete,
                $reviewReason,
                $requiredAction,
            ): array {
                // 1. De-duplicate or create customer in workspace
                $customer = Customer::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('phone', $normalizedPhone)
                    ->lockForUpdate()
                    ->first();

                $needsReview = (! $isComplete) || ! empty($reviewReason);
                $initialStatus = $needsReview ? CustomerStatus::NeedsReview : CustomerStatus::AwaitingPayment;

                if ($customer) {
                    $updatePayload = [
                        'last_conversation_at' => now(),
                    ];

                    if ($customerName !== 'عميل جديد' && ($customer->name === 'عميل جديد' || empty($customer->name))) {
                        $updatePayload['name'] = $customerName;
                    }

                    if (! empty($address) && empty($customer->address)) {
                        $updatePayload['address'] = $address;
                    }

                    if ($needsReview) {
                        $updatePayload['status'] = CustomerStatus::NeedsReview;
                    } elseif ($customer->status === CustomerStatus::NeedsReview) {
                        $updatePayload['status'] = CustomerStatus::AwaitingPayment;
                    }

                    $customer->update($updatePayload);
                } else {
                    $customer = Customer::withoutGlobalScopes()->create([
                        'workspace_id' => $workspace->id,
                        'name' => $customerName,
                        'phone' => $normalizedPhone,
                        'address' => $address,
                        'party_type' => Customer::PARTY_TYPE_INDIVIDUAL,
                        'status' => $initialStatus,
                        'notes' => $notes,
                        'last_conversation_at' => now(),
                    ]);
                }

                // 2. Link conversation to customer if not already linked
                if ($conversation && (int) $conversation->customer_id !== (int) $customer->id) {
                    $conversation->update(['customer_id' => $customer->id]);
                }

                // 3. Resolve items & calculate total amount
                $resolvedLines = [];
                $totalMinor = 0;
                $currency = 'SAR';

                foreach ($items as $itemData) {
                    $qty = max(1, (int) ($itemData['quantity'] ?? 1));
                    $catalogItemId = ! empty($itemData['chat_catalog_item_id']) ? (int) $itemData['chat_catalog_item_id'] : null;
                    $productName = trim((string) ($itemData['product_name'] ?? ''));
                    $unitPrice = $itemData['unit_price'] ?? null;

                    $catalogItem = null;
                    if ($catalogItemId) {
                        $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                            ->where('workspace_id', $workspace->id)
                            ->find($catalogItemId);
                    } elseif ($productName !== '') {
                        $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                            ->where('workspace_id', $workspace->id)
                            ->where('name', 'like', '%'.$productName.'%')
                            ->first();
                    }

                    if ($catalogItem) {
                        $catalogItemId = $catalogItem->id;
                        $productName = $catalogItem->name;
                        $currency = $catalogItem->currency ?: $currency;
                        if ($unitPrice === null || (float) $unitPrice <= 0) {
                            $unitPrice = $catalogItem->price;
                        }
                    }

                    $unitPriceDecimal = Money::of($unitPrice ?? 0);
                    $lineTotalDecimal = Money::quantityTimesUnitPrice($qty, $unitPriceDecimal);
                    $totalMinor += Money::minor($lineTotalDecimal);

                    $resolvedLines[] = [
                        'chat_catalog_item_id' => $catalogItemId,
                        'product_name' => $productName ?: 'منتج',
                        'variant_name' => $itemData['variant_name'] ?? null,
                        'chat_catalog_item_variant_id' => $itemData['variant_id'] ?? null,
                        'quantity' => $qty,
                        'unit_price' => $unitPriceDecimal,
                        'total_amount' => $lineTotalDecimal,
                        'currency' => $currency,
                    ];
                }

                $totalAmount = Money::fromMinor($totalMinor);

                // 4. Generate order number & create Order
                $orderNumber = $this->orderNumbers->nextCommerceNumber($workspace->id);

                $order = Order::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation?->id,
                    'order_number' => $orderNumber,
                    'status' => $needsReview ? 'draft' : 'confirmed',
                    'payment_status' => 'pending', // Strictly pending, AI never marks as paid
                    'fulfillment_status' => 'unfulfilled',
                    'shipping_status' => 'not_shipped',
                    'currency' => $currency,
                    'subtotal' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'notes' => $notes,
                    'review_reason' => $needsReview ? ($reviewReason ?: 'بيانات الطلب تحتاج إلى مراجعة وتأكيد بشري') : null,
                    'review_required_action' => $needsReview ? ($requiredAction ?: 'مراجعة تفاصيل الطلب مع العميل وتأكيده') : null,
                    'placed_at' => now(),
                ]);

                // 5. Create OrderItems
                foreach ($resolvedLines as $line) {
                    OrderItem::withoutGlobalScopes()->create([
                        'workspace_id' => $workspace->id,
                        'order_id' => $order->id,
                        'chat_catalog_item_id' => $line['chat_catalog_item_id'],
                        'chat_catalog_item_variant_id' => $line['chat_catalog_item_variant_id'],
                        'product_name' => $line['product_name'],
                        'variant_name' => $line['variant_name'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_amount' => $line['total_amount'],
                        'currency' => $line['currency'],
                    ]);
                }

                // 6. Update customer aggregates
                $this->aggregateService->incrementOrderAggregates(
                    $workspace->id,
                    $customer->id,
                    $totalAmount,
                    now(),
                );

                return [
                    'customer' => $customer->fresh(),
                    'order' => $order->fresh()->load('items'),
                    'is_review_required' => $needsReview,
                ];
            });
        });
    }
}
