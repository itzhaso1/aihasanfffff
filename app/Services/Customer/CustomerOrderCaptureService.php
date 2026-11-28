<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\CustomerStatus;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Workspace;
use App\Services\Location\LocationCaptureService;
use App\Services\Order\WorkspaceOrderNumberGenerator;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use BackedEnum;
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
        bool $isAdditionalOrder = false,
    ): array {
        $customerName = trim($customerName);
        if ($customerName === '') {
            $customerName = 'عميل جديد';
        }

        $normalizedPhone = $this->normalizePhone($phone);
        if ($normalizedPhone === '') {
            throw new InvalidArgumentException('Phone number is required to capture customer order.');
        }

        $cleanAddress = $address !== null && trim($address) !== '' ? trim($address) : null;
        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $customerName,
            $normalizedPhone,
            $items,
            $cleanNotes,
            $cleanAddress,
            $isComplete,
            $reviewReason,
            $requiredAction,
            $isAdditionalOrder,
        ): array {
            return DB::transaction(function () use (
                $workspace,
                $conversation,
                $customerName,
                $normalizedPhone,
                $items,
                $cleanNotes,
                $cleanAddress,
                $isComplete,
                $reviewReason,
                $requiredAction,
                $isAdditionalOrder,
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

                    if ($cleanAddress !== null && empty($customer->address)) {
                        $updatePayload['address'] = $cleanAddress;
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
                        'address' => $cleanAddress,
                        'party_type' => Customer::PARTY_TYPE_INDIVIDUAL,
                        'status' => $initialStatus,
                        'notes' => $cleanNotes,
                        'last_conversation_at' => now(),
                    ]);
                }

                // 2. Link conversation to customer only if unlinked (never hijack an existing customer's conversation when adding an order for another phone/recipient).
                $previousCustomerId = $conversation && $conversation->customer_id !== null
                    ? (int) $conversation->customer_id
                    : null;
                if ($conversation && $previousCustomerId === null) {
                    $conversation->update(['customer_id' => $customer->id]);
                }

                // 3. Resolve items & calculate total amount
                $resolvedLines = [];
                $totalMinor = 0;
                $currency = 'SAR';

                foreach ($items as $itemData) {
                    if (! is_array($itemData)) {
                        continue;
                    }

                    $line = $this->resolveItemLine($workspace, $itemData, $currency);
                    $currency = $line['currency'];
                    $totalMinor += Money::minor($line['total_amount']);
                    $resolvedLines[] = $line;
                }

                $totalAmount = Money::fromMinor($totalMinor);

                // 4. Generate order number & create Order
                $orderNumber = $this->orderNumbers->nextCommerceNumber($workspace->id);
                $orderMetadata = array_filter([
                    'shipping_address' => $cleanAddress,
                    'recipient_name' => $customerName,
                    'recipient_phone' => $normalizedPhone,
                    'is_additional_order' => ($isAdditionalOrder || ($previousCustomerId !== null && $previousCustomerId !== (int) $customer->id)) ? true : null,
                    'ordered_by_customer_id' => ($previousCustomerId !== null && $previousCustomerId !== (int) $customer->id) ? $previousCustomerId : null,
                ], static fn (mixed $v): bool => $v !== null && $v !== '');

                $orderNotes = $this->composeOrderNotes($cleanNotes, $cleanAddress, $customerName, $normalizedPhone, $orderMetadata);

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
                    'notes' => $orderNotes,
                    'metadata' => $orderMetadata !== [] ? $orderMetadata : null,
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
                        'item_type' => $line['item_type'],
                        'sku' => $line['sku'],
                        'classification' => $line['classification'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_amount' => $line['total_amount'],
                        'currency' => $line['currency'],
                    ]);
                }

                // 6. Attach existing confirmed location if order was created after location capture
                $linkedLocation = app(LocationCaptureService::class)->attachExistingLocationToNewOrder($order, $conversation);
                if ($linkedLocation !== null) {
                    $order = $order->fresh() ?? $order;
                }

                // 7. Update customer aggregates
                $this->aggregateService->incrementOrderAggregates(
                    $workspace->id,
                    $customer->id,
                    $totalAmount,
                    now(),
                );

                return [
                    'customer' => $customer->fresh(),
                    'order' => $order->fresh()->load(['items', 'deliveryLocation']),
                    'is_review_required' => $needsReview,
                ];
            });
        });
    }

    /**
     * Resolve catalog item, variant, price, and product type for one order line.
     *
     * @param  array<string, mixed>  $itemData
     * @return array{
     *     chat_catalog_item_id: int|null,
     *     chat_catalog_item_variant_id: int|null,
     *     product_name: string,
     *     variant_name: string|null,
     *     item_type: string|null,
     *     sku: string|null,
     *     classification: string|null,
     *     quantity: int,
     *     unit_price: string,
     *     total_amount: string,
     *     currency: string
     * }
     */
    private function resolveItemLine(Workspace $workspace, array $itemData, string $defaultCurrency): array
    {
        $qty = max(1, (int) ($itemData['quantity'] ?? 1));
        $rawItemId = $itemData['chat_catalog_item_id'] ?? $itemData['item_id'] ?? $itemData['id'] ?? null;
        $catalogItemId = is_numeric($rawItemId) && (int) $rawItemId > 0 ? (int) $rawItemId : null;
        $productName = trim((string) ($itemData['product_name'] ?? $itemData['name'] ?? ''));

        $rawVariantId = $itemData['chat_catalog_item_variant_id'] ?? $itemData['variant_id'] ?? null;
        $variantId = is_numeric($rawVariantId) && (int) $rawVariantId > 0 ? (int) $rawVariantId : null;
        $variantName = trim((string) ($itemData['variant_name'] ?? ''));
        $productType = trim((string) ($itemData['product_type'] ?? $itemData['type'] ?? ''));
        $unitPrice = $itemData['unit_price'] ?? $itemData['price'] ?? null;

        $catalogItem = null;
        if ($catalogItemId !== null) {
            $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                ->with('variants')
                ->where('workspace_id', $workspace->id)
                ->find($catalogItemId);
        }

        if ($catalogItem === null && $variantId !== null) {
            $directVariant = ChatCatalogItemVariant::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->find($variantId);
            if ($directVariant !== null) {
                $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                    ->with('variants')
                    ->where('workspace_id', $workspace->id)
                    ->find($directVariant->chat_catalog_item_id);
            }
        }

        if ($catalogItem === null && $productName !== '') {
            $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                ->with('variants')
                ->where('workspace_id', $workspace->id)
                ->where('name', $productName)
                ->first()
                ?? ChatCatalogItem::withoutGlobalScopes()
                    ->with('variants')
                    ->where('workspace_id', $workspace->id)
                    ->where('name', 'like', '%'.$productName.'%')
                    ->first();
        }

        $currency = $defaultCurrency;
        $sku = null;
        $classification = null;
        $itemType = $productType !== '' ? $productType : null;
        $variant = null;

        if ($catalogItem !== null) {
            $catalogItemId = $catalogItem->id;
            $productName = $catalogItem->name;
            $currency = $catalogItem->currency ?: $currency;
            $sku = $catalogItem->sku ?: null;
            $rawClassification = $catalogItem->classification instanceof BackedEnum
                ? (string) $catalogItem->classification->value
                : (is_string($catalogItem->classification) ? $catalogItem->classification : null);
            $classification = $rawClassification ?: null;
            $itemType = $itemType ?: ($catalogItem->item_kind ?: $classification);

            $variant = $this->resolveVariant($catalogItem, $variantId, $variantName !== '' ? $variantName : $productType);
            if ($variant !== null) {
                $variantId = $variant->id;
                $variantName = $variant->name;
                $sku = $variant->sku ?: $sku;

                if ($productType !== '' && strcasecmp($productType, $variant->name) === 0) {
                    $itemType = $catalogItem->item_kind ?: $classification ?: $productType;
                }

                $variantPrice = $variant->sale_price ?? $variant->price;
                $hasValidVariantPrice = $variantPrice !== null && (float) $variantPrice > 0;
                $passedSameAsParent = $unitPrice !== null
                    && $catalogItem->price !== null
                    && (float) $unitPrice === (float) $catalogItem->price
                    && $hasValidVariantPrice
                    && (float) $variantPrice !== (float) $catalogItem->price;

                if ($hasValidVariantPrice && ($unitPrice === null || (float) $unitPrice <= 0 || $passedSameAsParent)) {
                    $unitPrice = $variantPrice;
                }
            }

            if ($unitPrice === null || (float) $unitPrice <= 0) {
                $unitPrice = $catalogItem->sale_price ?? $catalogItem->price;
            }
        }

        if ($variantName === '' && $productType !== '') {
            $variantName = $productType;
        }

        $unitPriceDecimal = Money::of($unitPrice ?? 0);
        $lineTotalDecimal = Money::quantityTimesUnitPrice($qty, $unitPriceDecimal);

        return [
            'chat_catalog_item_id' => $catalogItemId,
            'chat_catalog_item_variant_id' => $variantId,
            'product_name' => $productName !== '' ? $productName : 'منتج',
            'variant_name' => $variantName !== '' ? $variantName : null,
            'item_type' => $itemType,
            'sku' => $sku,
            'classification' => $classification,
            'quantity' => $qty,
            'unit_price' => $unitPriceDecimal,
            'total_amount' => $lineTotalDecimal,
            'currency' => $currency,
        ];
    }

    private function resolveVariant(ChatCatalogItem $catalogItem, ?int $variantId, string $hint): ?ChatCatalogItemVariant
    {
        $variants = $catalogItem->variants;
        if ($variantId !== null) {
            $matched = $variants->firstWhere('id', $variantId);
            if ($matched instanceof ChatCatalogItemVariant) {
                return $matched;
            }
        }

        $needle = mb_strtolower(trim($hint));
        if ($needle === '') {
            return null;
        }

        foreach ($variants as $variant) {
            if (mb_strtolower(trim((string) $variant->name)) === $needle) {
                return $variant;
            }
        }

        foreach ($variants as $variant) {
            $name = mb_strtolower(trim((string) $variant->name));
            if ($name !== '' && (str_contains($name, $needle) || str_contains($needle, $name))) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Modify an existing unpaid, unfulfilled order belonging strictly to the current conversation or customer.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function modifyOrder(
        Workspace $workspace,
        Conversation $conversation,
        ?string $orderNumber = null,
        string $mode = 'add_items',
        array $items = [],
        ?string $customerName = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $notes = null,
    ): Order {
        $normalizedMode = in_array($mode, ['add_items', 'replace_items', 'update_details'], true) ? $mode : 'add_items';

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $orderNumber,
            $normalizedMode,
            $items,
            $customerName,
            $phone,
            $address,
            $notes,
        ): Order {
            return DB::transaction(function () use (
                $workspace,
                $conversation,
                $orderNumber,
                $normalizedMode,
                $items,
                $customerName,
                $phone,
                $address,
                $notes,
            ): Order {
                $order = $this->findOwnedOrder($workspace, $conversation, $orderNumber);
                if (! $order instanceof Order) {
                    throw new InvalidArgumentException('لم يتم العثور على طلب نشط مرتبط بهذه المحادثة لتعديله.');
                }
                if ($order->status === 'cancelled') {
                    throw new InvalidArgumentException('لا يمكن تعديل طلب ملغى.');
                }
                if ($order->payment_status === 'paid' || $order->fulfillment_status === 'fulfilled') {
                    throw new InvalidArgumentException('الطلب مدفوع أو مكتمل التنفيذ، يرجى التحويل للدعم البشري لتعديله.');
                }

                $order->loadMissing('items');
                $metadata = is_array($order->metadata) ? $order->metadata : [];
                $currency = $order->currency ?: 'SAR';

                if ($customerName !== null && $customerName !== '') {
                    $metadata['recipient_name'] = $customerName;
                }
                if ($phone !== null && $phone !== '') {
                    $metadata['recipient_phone'] = $this->normalizePhone($phone);
                }
                if ($address !== null && $address !== '') {
                    $metadata['shipping_address'] = $address;
                }
                $metadata['last_modified_at'] = now()->toIso8601String();

                if ($items !== [] && $normalizedMode !== 'update_details') {
                    $incomingLines = [];
                    foreach ($items as $itemData) {
                        if (! is_array($itemData)) {
                            continue;
                        }
                        $incomingLines[] = $this->resolveItemLine($workspace, $itemData, $currency);
                    }

                    if ($incomingLines !== []) {
                        if ($normalizedMode === 'replace_items') {
                            OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->delete();
                            foreach ($incomingLines as $line) {
                                $this->createOrderItemRow($workspace, $order, $line);
                            }
                        } else {
                            $this->mergeOrderLines($workspace, $order, $incomingLines);
                        }
                    }
                }

                $freshItems = OrderItem::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('order_id', $order->id)
                    ->get();

                $totalMinor = 0;
                foreach ($freshItems as $freshItem) {
                    $totalMinor += Money::minor((string) $freshItem->total_amount);
                    $currency = $freshItem->currency ?: $currency;
                }

                $totalAmount = Money::fromMinor($totalMinor);
                $updatedNotes = $this->composeOrderNotes(
                    $notes ?? $order->notes,
                    $address,
                    $customerName,
                    isset($metadata['recipient_phone']) && is_string($metadata['recipient_phone']) ? $metadata['recipient_phone'] : null,
                    $metadata,
                );

                $order->update([
                    'currency' => $currency,
                    'subtotal' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'notes' => $updatedNotes,
                    'metadata' => $metadata,
                ]);

                $this->aggregateService->refreshOrderAggregates($workspace->id, (int) $order->customer_id);

                return $order->fresh()->load('items');
            });
        });
    }

    /**
     * Cancel an existing unpaid, unfulfilled order belonging strictly to the current conversation or customer.
     */
    public function cancelOrder(
        Workspace $workspace,
        Conversation $conversation,
        ?string $orderNumber = null,
        ?string $reason = null,
    ): Order {
        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $orderNumber,
            $reason,
        ): Order {
            return DB::transaction(function () use (
                $workspace,
                $conversation,
                $orderNumber,
                $reason,
            ): Order {
                $order = $this->findOwnedOrder($workspace, $conversation, $orderNumber);
                if (! $order instanceof Order) {
                    throw new InvalidArgumentException('لم يتم العثور على طلب نشط مرتبط بهذه المحادثة لإلغائه.');
                }
                if ($order->status === 'cancelled') {
                    throw new InvalidArgumentException('الطلب ملغى بالفعل.');
                }
                if ($order->payment_status === 'paid' || $order->fulfillment_status === 'fulfilled') {
                    throw new InvalidArgumentException('الطلب مدفوع أو تم تنفيذه، يرجى تحويل العميل للدعم البشري لإلغائه أو استرجاعه.');
                }

                $metadata = is_array($order->metadata) ? $order->metadata : [];
                if ($reason !== null && $reason !== '') {
                    $metadata['cancellation_reason'] = $reason;
                }
                $metadata['cancelled_by'] = 'ai_employee';

                $notes = trim((string) ($order->notes ?? ''));
                if ($reason !== null && $reason !== '') {
                    $cancelLine = 'سبب الإلغاء: '.$reason;
                    $notes = $notes !== '' ? $notes."\n".$cancelLine : $cancelLine;
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'notes' => $notes !== '' ? $notes : null,
                    'metadata' => $metadata,
                ]);

                $this->aggregateService->refreshOrderAggregates($workspace->id, (int) $order->customer_id);

                return $order->fresh()->load('items');
            });
        });
    }

    private function findOwnedOrder(Workspace $workspace, Conversation $conversation, ?string $orderNumber): ?Order
    {
        $customerId = $conversation->customer_id !== null ? (int) $conversation->customer_id : null;
        $conversationId = $conversation->id > 0 ? (int) $conversation->id : null;

        if (($customerId === null || $customerId <= 0) && ($conversationId === null || $conversationId <= 0)) {
            return null;
        }

        $query = Order::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where(function ($ownerQuery) use ($customerId, $conversationId): void {
                if ($conversationId !== null && $conversationId > 0) {
                    $ownerQuery->where('conversation_id', $conversationId);
                }
                if ($customerId !== null && $customerId > 0) {
                    if ($conversationId !== null && $conversationId > 0) {
                        $ownerQuery->orWhere('customer_id', $customerId);
                    } else {
                        $ownerQuery->where('customer_id', $customerId);
                    }
                }
            })
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->lockForUpdate();

        if ($orderNumber !== null && $orderNumber !== '') {
            $normalizedNum = ltrim($orderNumber, '#');
            $query->where(function ($numQuery) use ($orderNumber, $normalizedNum): void {
                $numQuery->where('order_number', $orderNumber)
                    ->orWhere('order_number', $normalizedNum)
                    ->orWhere('order_number', '#'.$normalizedNum);
            });
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function createOrderItemRow(Workspace $workspace, Order $order, array $line): OrderItem
    {
        return OrderItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'chat_catalog_item_id' => $line['chat_catalog_item_id'],
            'chat_catalog_item_variant_id' => $line['chat_catalog_item_variant_id'],
            'product_name' => $line['product_name'],
            'variant_name' => $line['variant_name'],
            'item_type' => $line['item_type'],
            'sku' => $line['sku'],
            'classification' => $line['classification'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'total_amount' => $line['total_amount'],
            'currency' => $line['currency'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $incomingLines
     */
    private function mergeOrderLines(Workspace $workspace, Order $order, array $incomingLines): void
    {
        $existingItems = OrderItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('order_id', $order->id)
            ->get();

        foreach ($incomingLines as $line) {
            $match = $existingItems->first(function (OrderItem $existing) use ($line): bool {
                if ($line['chat_catalog_item_id'] !== null && (int) $existing->chat_catalog_item_id === (int) $line['chat_catalog_item_id']) {
                    return (int) ($existing->chat_catalog_item_variant_id ?? 0) === (int) ($line['chat_catalog_item_variant_id'] ?? 0)
                        && mb_strtolower((string) ($existing->variant_name ?? '')) === mb_strtolower((string) ($line['variant_name'] ?? ''));
                }

                return mb_strtolower((string) $existing->product_name) === mb_strtolower((string) $line['product_name'])
                    && mb_strtolower((string) ($existing->variant_name ?? '')) === mb_strtolower((string) ($line['variant_name'] ?? ''));
            });

            if ($match instanceof OrderItem) {
                $newQty = (int) $match->quantity + (int) $line['quantity'];
                $unitPrice = Money::of((float) $line['unit_price'] > 0 ? $line['unit_price'] : $match->unit_price);
                $match->update([
                    'quantity' => $newQty,
                    'unit_price' => $unitPrice,
                    'total_amount' => Money::quantityTimesUnitPrice($newQty, $unitPrice),
                ]);

                continue;
            }

            $this->createOrderItemRow($workspace, $order, $line);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function composeOrderNotes(
        ?string $baseNotes,
        ?string $address,
        ?string $recipientName,
        ?string $recipientPhone,
        array $metadata,
    ): ?string {
        $lines = [];
        $trimmedBase = trim((string) ($baseNotes ?? ''));
        if ($trimmedBase !== '') {
            $lines[] = $trimmedBase;
        }
        if ($address !== null && trim($address) !== '' && ! str_contains($trimmedBase, trim($address))) {
            $lines[] = 'العنوان: '.trim($address);
        }
        if (! empty($metadata['is_additional_order'])) {
            if ($recipientName !== null && trim($recipientName) !== '' && ! str_contains($trimmedBase, trim($recipientName))) {
                $lines[] = 'المستلم: '.trim($recipientName);
            }
            if ($recipientPhone !== null && trim($recipientPhone) !== '' && ! str_contains($trimmedBase, trim($recipientPhone))) {
                $lines[] = 'جوال المستلم: '.trim($recipientPhone);
            }
        }

        return $lines !== [] ? implode("\n", $lines) : null;
    }
}
