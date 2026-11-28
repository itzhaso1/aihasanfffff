<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\CustomerStatus;
use App\Models\AiSetting;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\LocationCaptureRevision;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Workspace;
use App\Services\AI\Inbox\Memory\ConversationFactsMemory;
use App\Services\Chat\ChatCatalogInventoryPresenter;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Customer\Exceptions\AmbiguousOrderSelectionException;
use App\Services\Customer\Exceptions\OrderFeeConfirmationRequiredException;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Location\LocationCaptureService;
use App\Services\Location\ReverseGeocodingService;
use App\Services\Order\WorkspaceOrderNumberGenerator;
use App\Support\Money\Money;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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

                $workspaceHasCatalog = ChatCatalogItem::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('workspace_id', $workspace->id)
                    ->exists();

                // 3. Resolve items & calculate total amount
                $resolvedLines = [];
                $subtotalMinor = 0;
                $currency = 'SAR';

                foreach ($items as $itemData) {
                    if (! is_array($itemData)) {
                        continue;
                    }

                    $line = $this->resolveItemLine($workspace, $itemData, $currency, $workspaceHasCatalog);
                    $currency = $line['currency'];
                    $subtotalMinor += Money::minor($line['total_amount']);
                    $resolvedLines[] = $line;
                }

                if ($resolvedLines === []) {
                    throw new InvalidArgumentException('يجب تحديد منتج واحد على الأقل لتسجيل الطلب.');
                }

                $zoneEval = $cleanAddress !== null
                    ? $this->evaluateDeliveryZoneAndFee($workspace, null, $cleanAddress, null)
                    : null;
                if ($zoneEval !== null && ($zoneEval['out_of_zone'] ?? false) === true) {
                    throw new InvalidArgumentException('العنوان المحدد ('.$cleanAddress.') خارج نطاق التوصيل المتاح حالياً.');
                }

                $shippingMinor = ($zoneEval !== null && ($zoneEval['matched'] ?? false) === true)
                    ? (int) $zoneEval['shipping_minor']
                    : 0;
                $subtotalAmount = Money::fromMinor($subtotalMinor);
                $shippingAmount = Money::fromMinor($shippingMinor);
                $totalAmount = Money::fromMinor($subtotalMinor + $shippingMinor);

                // 4. Generate order number & create Order
                $orderNumber = $this->orderNumbers->nextCommerceNumber($workspace->id);
                $orderMetadata = array_filter([
                    'shipping_address' => $cleanAddress,
                    'recipient_name' => $customerName,
                    'recipient_phone' => $normalizedPhone,
                    'delivery_zone' => ($zoneEval['zone_name'] ?? null),
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
                    'subtotal' => $subtotalAmount,
                    'shipping_amount' => $shippingAmount,
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

                // 6. Attach existing confirmed location only when this is not a separate explicit text-address additional order
                $shouldAttachPriorLocation = ! $isAdditionalOrder || $cleanAddress === null;
                if ($shouldAttachPriorLocation) {
                    $linkedLocation = app(LocationCaptureService::class)->attachExistingLocationToNewOrder($order, $conversation);
                    if ($linkedLocation !== null) {
                        $order = $order->fresh() ?? $order;
                    }
                }

                // 7. Update customer aggregates
                $this->aggregateService->incrementOrderAggregates(
                    $workspace->id,
                    $customer->id,
                    $totalAmount,
                    now(),
                );

                $freshOrder = $order->fresh()->load(['items', 'deliveryLocation']);

                DB::afterCommit(function () use ($workspace, $conversation, $freshOrder, $cleanAddress): void {
                    if ($conversation !== null) {
                        $this->syncConversationMemory($workspace, $conversation, $freshOrder, $cleanAddress);
                    }
                    app(InboxRealtimePublisher::class)->publishOrderUpdated($freshOrder, $conversation, 'created');
                });

                return [
                    'customer' => $customer->fresh(),
                    'order' => $freshOrder,
                    'is_review_required' => $needsReview,
                ];
            });
        });
    }

    /**
     * Resolve catalog item, variant, price, and product type for one order line.
     * Enforces real-time active, non-deleted, in-stock catalog verification when workspace has catalog items or an ID is passed.
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
    private function resolveItemLine(
        Workspace $workspace,
        array $itemData,
        string $defaultCurrency,
        ?bool $workspaceHasCatalog = null,
    ): array {
        $qty = max(1, (int) ($itemData['quantity'] ?? 1));
        $rawItemId = $itemData['chat_catalog_item_id'] ?? $itemData['item_id'] ?? $itemData['id'] ?? null;
        $catalogItemId = is_numeric($rawItemId) && (int) $rawItemId > 0 ? (int) $rawItemId : null;
        $productName = trim((string) ($itemData['product_name'] ?? $itemData['name'] ?? ''));

        $rawVariantId = $itemData['chat_catalog_item_variant_id'] ?? $itemData['variant_id'] ?? null;
        $variantId = is_numeric($rawVariantId) && (int) $rawVariantId > 0 ? (int) $rawVariantId : null;
        $variantName = trim((string) ($itemData['variant_name'] ?? ''));
        $productType = trim((string) ($itemData['product_type'] ?? $itemData['type'] ?? ''));
        $unitPrice = $itemData['unit_price'] ?? $itemData['price'] ?? null;

        $workspaceHasCatalog ??= ChatCatalogItem::withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->exists();

        $catalogItem = null;
        if ($catalogItemId !== null) {
            $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                ->withTrashed()
                ->with(['variants' => fn ($v) => $v->withoutGlobalScopes()->withTrashed()])
                ->where('workspace_id', $workspace->id)
                ->find($catalogItemId);
        }

        if ($catalogItem === null && $variantId !== null) {
            $directVariant = ChatCatalogItemVariant::withoutGlobalScopes()
                ->withTrashed()
                ->where('workspace_id', $workspace->id)
                ->find($variantId);
            if ($directVariant !== null) {
                $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                    ->withTrashed()
                    ->with(['variants' => fn ($v) => $v->withoutGlobalScopes()->withTrashed()])
                    ->where('workspace_id', $workspace->id)
                    ->find($directVariant->chat_catalog_item_id);
            }
        }

        if ($catalogItem === null && $productName !== '') {
            $catalogItem = ChatCatalogItem::withoutGlobalScopes()
                ->withTrashed()
                ->with(['variants' => fn ($v) => $v->withoutGlobalScopes()->withTrashed()])
                ->where('workspace_id', $workspace->id)
                ->where('name', $productName)
                ->first()
                ?? ChatCatalogItem::withoutGlobalScopes()
                    ->withTrashed()
                    ->with(['variants' => fn ($v) => $v->withoutGlobalScopes()->withTrashed()])
                    ->where('workspace_id', $workspace->id)
                    ->where('name', 'like', '%'.$productName.'%')
                    ->orderByRaw('CASE WHEN deleted_at IS NULL AND is_active = 1 THEN 0 ELSE 1 END')
                    ->first();
        }

        if ($workspaceHasCatalog || $catalogItemId !== null || $variantId !== null) {
            if (! $catalogItem instanceof ChatCatalogItem) {
                throw new InvalidArgumentException('المنتج "'.($productName !== '' ? $productName : '#'.(string) $catalogItemId).'" غير موجود في قائمة المنتجات الحالية للمتجر.');
            }
            if ($catalogItem->trashed()) {
                throw new InvalidArgumentException('المنتج "'.$catalogItem->name.'" محذوف ولم يعد متاحاً للطلب.');
            }
            $rawStatus = strtolower(trim((string) ($catalogItem->status ?? 'active')));
            if (! $catalogItem->is_active
                || ! $catalogItem->is_visible
                || $catalogItem->archived_at !== null
                || in_array($rawStatus, ['inactive', 'disabled', 'archived', 'draft'], true)) {
                throw new InvalidArgumentException('المنتج "'.$catalogItem->name.'" معطّل أو غير متاح للبيع حالياً.');
            }
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
            if ($variantId !== null && ! $variant instanceof ChatCatalogItemVariant) {
                throw new InvalidArgumentException('المتغير أو الخيار المحدد للمنتج غير متوفر حالياً.');
            }

            if ($variant !== null) {
                if ((method_exists($variant, 'trashed') && $variant->trashed())
                    || ! $variant->is_active
                    || ($variant->is_visible === false)
                    || in_array(strtolower((string) ($variant->status ?? 'published')), ['inactive', 'disabled', 'archived', 'draft'], true)) {
                    throw new InvalidArgumentException('الخيار "'.$variant->name.'" للمنتج "'.$catalogItem->name.'" غير متاح حالياً.');
                }

                $variantId = $variant->id;
                $variantName = $variant->name;
                $sku = $variant->sku ?: $sku;

                if ($productType !== '' && strcasecmp($productType, $variant->name) === 0) {
                    $itemType = $catalogItem->item_kind ?: $classification ?: $productType;
                }

                $this->assertInventoryAvailable($workspace, $catalogItem, $variant, $qty);

                $authoritativeVariantPrice = $variant->sale_price ?? $variant->price;
                if ($authoritativeVariantPrice !== null && (float) $authoritativeVariantPrice > 0) {
                    $unitPrice = $authoritativeVariantPrice;
                } elseif (($catalogItem->sale_price ?? $catalogItem->price) !== null && (float) ($catalogItem->sale_price ?? $catalogItem->price) > 0) {
                    $unitPrice = $catalogItem->sale_price ?? $catalogItem->price;
                }
            } else {
                $this->assertInventoryAvailable($workspace, $catalogItem, null, $qty);

                $authoritativeItemPrice = $catalogItem->sale_price ?? $catalogItem->price;
                if ($authoritativeItemPrice !== null && (float) $authoritativeItemPrice > 0) {
                    $unitPrice = $authoritativeItemPrice;
                }
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

    private function assertInventoryAvailable(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        int $quantity,
    ): void {
        $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
        $presenter = app(ChatCatalogInventoryPresenter::class);
        $stockInfo = $variant instanceof ChatCatalogItemVariant
            ? $presenter->forVariant($catalog, $variant)
            : $presenter->forItem($catalog, $item);

        $policyValue = (string) ($stockInfo['policy'] ?? ChatCatalogInventoryPolicy::FiniteStock->value);
        if (in_array($policyValue, [
            ChatCatalogInventoryPolicy::Unlimited->value,
            ChatCatalogInventoryPolicy::NotTracked->value,
            ChatCatalogInventoryPolicy::OnRequest->value,
            ChatCatalogInventoryPolicy::Backorder->value,
        ], true)) {
            return;
        }

        if (($stockInfo['available'] ?? false) === false) {
            throw new InvalidArgumentException('المنتج "'.$item->name.'" غير متوفر في المخزون حالياً.');
        }

        $availableQty = $stockInfo['quantity'] ?? null;
        if ($availableQty !== null && $quantity > (int) $availableQty) {
            throw new InvalidArgumentException('الكمية المطلوبة من "'.$item->name.'" غير متوفرة؛ المتاح حالياً '.$availableQty.'.');
        }
    }

    private function resolveVariant(ChatCatalogItem $catalogItem, ?int $variantId, string $hint): ?ChatCatalogItemVariant
    {
        $variants = $catalogItem->variants;
        if ($variantId !== null) {
            $matched = $variants->firstWhere('id', $variantId);
            if ($matched instanceof ChatCatalogItemVariant) {
                return $matched;
            }

            return null;
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
     * List orders strictly scoped to the current workspace AND current customer/conversation.
     *
     * @return list<array<string, mixed>>
     */
    public function listCustomerOrders(
        Workspace $workspace,
        Conversation $conversation,
        bool $activeOnly = false,
        ?string $orderNumber = null,
        ?string $queryHint = null,
        int $limit = 15,
    ): array {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $conversation, $activeOnly, $orderNumber, $queryHint, $limit): array {
            $query = $this->ownedOrdersQuery($workspace, $conversation)
                ->with(['items:id,order_id,product_name,variant_name,item_type,quantity,unit_price,total_amount,currency', 'deliveryLocation'])
                ->orderByDesc('id');

            if ($activeOnly) {
                $query->where('status', '!=', 'cancelled')
                    ->where('status', '!=', 'completed')
                    ->where('fulfillment_status', '!=', 'fulfilled');
            }

            if ($orderNumber !== null && trim($orderNumber) !== '') {
                $this->applyOrderNumberFilter($query, $orderNumber);
            }

            $rows = $query->limit(max(1, min(30, $limit)))->get();
            if ($queryHint !== null && trim($queryHint) !== '') {
                $needle = mb_strtolower(trim($queryHint));
                $filtered = $rows->filter(function (Order $order) use ($needle): bool {
                    $hay = mb_strtolower(implode(' ', [
                        (string) $order->order_number,
                        (string) data_get($order->metadata, 'shipping_address', ''),
                        (string) $order->notes,
                        $order->items->pluck('product_name')->implode(' '),
                    ]));

                    return str_contains($hay, $needle);
                })->values();
                if ($filtered->isNotEmpty()) {
                    $rows = $filtered;
                }
            }

            return $rows->map(fn (Order $order): array => $this->serializeOrderSummary($order))->values()->all();
        });
    }

    /**
     * Fetch detailed info for a single verified owned order.
     *
     * @return array<string, mixed>|null
     */
    public function getOwnedOrderDetails(
        Workspace $workspace,
        Conversation $conversation,
        ?string $orderNumber = null,
        ?string $orderHint = null,
    ): ?array {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $conversation, $orderNumber, $orderHint): ?array {
            $order = $this->resolveOwnedOrderForAction(
                workspace: $workspace,
                conversation: $conversation,
                orderNumber: $orderNumber,
                orderHint: $orderHint,
                forCancellation: false,
                lock: false,
                activeOnly: false,
            );

            return $order instanceof Order ? $this->serializeOrderSummary($order->loadMissing(['items', 'deliveryLocation'])) : null;
        });
    }

    /**
     * Dedicated, atomic delivery address + GPS coordinates update on a specific verified order.
     * Re-evaluates shipping zone and fees before committing if the workspace defines zones/fees.
     *
     * @return array<string, mixed>
     */
    public function updateOrderAddress(
        Workspace $workspace,
        Conversation $conversation,
        ?string $orderNumber = null,
        ?string $address = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $deliveryLocationId = null,
        ?string $recipientName = null,
        ?string $phone = null,
        ?string $notes = null,
        ?string $orderHint = null,
        bool $confirmFeeChange = false,
    ): array {
        $cleanAddress = $address !== null && trim($address) !== '' ? trim($address) : null;
        $hasGps = $latitude !== null && $longitude !== null;
        if ($hasGps && ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0)) {
            throw new InvalidArgumentException('إحداثيات الموقع الجغرافي غير صالحة.');
        }

        if ($cleanAddress === null && ! $hasGps && $deliveryLocationId === null) {
            throw new InvalidArgumentException('يرجى تزويد العنوان الجديد أو إحداثيات الموقع لتحديث عنوان التوصيل.');
        }

        Log::info('order.address_update.requested', array_merge(CorrelationContext::logContext(), [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'order_number' => $orderNumber,
            'has_text_address' => $cleanAddress !== null,
            'has_gps' => $hasGps,
            'delivery_location_id' => $deliveryLocationId,
            'confirm_fee_change' => $confirmFeeChange,
        ]));

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $orderNumber,
            $cleanAddress,
            $latitude,
            $longitude,
            $hasGps,
            $deliveryLocationId,
            $recipientName,
            $phone,
            $notes,
            $orderHint,
            $confirmFeeChange,
        ): array {
            $geoSummary = null;
            if ($hasGps && $cleanAddress === null) {
                try {
                    $geo = app(ReverseGeocodingService::class)->reverse((float) $latitude, (float) $longitude);
                    if ($geo->resolved() && $geo->formattedAddress) {
                        $geoSummary = [
                            'formatted_address' => $geo->formattedAddress,
                            'city' => $geo->city,
                            'district' => $geo->district,
                            'street' => $geo->street,
                            'postal_code' => $geo->postalCode,
                            'country_code' => $geo->countryCode,
                            'country' => $geo->country,
                            'place_name' => $geo->placeName,
                            'provider' => $geo->provider,
                        ];
                    }
                } catch (Throwable) {
                    // Best-effort reverse geocoding; coordinates remain authoritative.
                }
            }

            try {
                return DB::transaction(function () use (
                    $workspace,
                    $conversation,
                    $orderNumber,
                    $cleanAddress,
                    $latitude,
                    $longitude,
                    $hasGps,
                    $deliveryLocationId,
                    $recipientName,
                    $phone,
                    $notes,
                    $orderHint,
                    $confirmFeeChange,
                    $geoSummary,
                ): array {
                    $order = $this->resolveOwnedOrderForAction(
                        workspace: $workspace,
                        conversation: $conversation,
                        orderNumber: $orderNumber,
                        orderHint: $orderHint,
                        forCancellation: false,
                        lock: true,
                    );

                    if (! $order instanceof Order) {
                        throw new InvalidArgumentException('لم يتم العثور على طلب نشط مرتبط بهذه المحادثة لتعديل عنوانه.');
                    }
                    if ($order->status === 'cancelled') {
                        throw new InvalidArgumentException('لا يمكن تعديل عنوان طلب ملغى.');
                    }
                    if ($order->status === 'completed'
                        || $order->fulfillment_status === 'fulfilled'
                        || in_array((string) $order->shipping_status, ['shipped', 'delivered', 'returned'], true)) {
                        throw new InvalidArgumentException('تم شحن الطلب أو إكماله ولا يمكن تعديل عنوانه آلياً، يرجى التواصل مع خدمة العملاء.');
                    }

                    $order->loadMissing(['items', 'deliveryLocation']);
                    $metadata = is_array($order->metadata) ? $order->metadata : [];
                    $oldAddress = is_string($metadata['shipping_address'] ?? null) ? $metadata['shipping_address'] : null;

                    $linkedLocation = null;
                    if ($deliveryLocationId !== null) {
                        $candidateLoc = CustomerLocation::withoutGlobalScopes()
                            ->where('workspace_id', $workspace->id)
                            ->whereKey($deliveryLocationId)
                            ->first();
                        if (! $candidateLoc instanceof CustomerLocation
                            || ((int) $candidateLoc->conversation_id !== (int) $conversation->id
                                && (int) ($candidateLoc->customer_id ?? 0) !== (int) ($conversation->customer_id ?? -1))) {
                            throw new InvalidArgumentException('معرّف الموقع الجغرافي غير صالح أو لا يخص هذه المحادثة.');
                        }
                        $linkedLocation = $candidateLoc;
                    } elseif ($hasGps) {
                        $prevOrderLocId = $order->delivery_location_id ? (int) $order->delivery_location_id : null;
                        $resolvedFormatted = $cleanAddress
                            ?? ($geoSummary['formatted_address'] ?? null)
                            ?? sprintf('إحداثيات (%s, %s)', number_format((float) $latitude, 6, '.', ''), number_format((float) $longitude, 6, '.', ''));

                        $linkedLocation = CustomerLocation::withoutGlobalScopes()->create([
                            'workspace_id' => $workspace->id,
                            'customer_id' => $order->customer_id ?? $conversation->customer_id,
                            'conversation_id' => $conversation->id,
                            'order_id' => $order->id,
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'source' => 'ai_address_update',
                            'platform' => $conversation->channel ?: 'web',
                            'verification_status' => CustomerLocation::STATUS_CONFIRMED,
                            'formatted_address' => $resolvedFormatted,
                            'city' => $geoSummary['city'] ?? null,
                            'district' => $geoSummary['district'] ?? null,
                            'street' => $geoSummary['street'] ?? null,
                            'postal_code' => $geoSummary['postal_code'] ?? null,
                            'country_code' => $geoSummary['country_code'] ?? null,
                            'country' => $geoSummary['country'] ?? null,
                            'place_name' => $geoSummary['place_name'] ?? null,
                            'geocoding_provider' => $geoSummary['provider'] ?? null,
                            'geocoding_status' => $geoSummary !== null ? 'resolved' : 'manual',
                            'confirmed_at' => now(),
                        ]);

                        if ($prevOrderLocId !== null && $prevOrderLocId !== (int) $linkedLocation->id) {
                            $prevLoc = CustomerLocation::withoutGlobalScopes()
                                ->where('workspace_id', $workspace->id)
                                ->find($prevOrderLocId);
                            if ($prevLoc instanceof CustomerLocation) {
                                $prevLoc->forceFill([
                                    'superseded_by_id' => $linkedLocation->id,
                                    'verification_status' => CustomerLocation::STATUS_SUPERSEDED,
                                ])->save();
                            }
                        }

                        LocationCaptureRevision::withoutGlobalScopes()->create([
                            'workspace_id' => $workspace->id,
                            'customer_location_id' => $linkedLocation->id,
                            'conversation_id' => $conversation->id,
                            'customer_id' => $order->customer_id ?? $conversation->customer_id,
                            'order_id' => $order->id,
                            'event_type' => 'updated',
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'source' => 'ai_address_update',
                            'platform' => $conversation->channel ?: 'web',
                            'formatted_address' => $resolvedFormatted,
                            'actor_type' => 'ai',
                            'created_at' => now(),
                        ]);
                    }

                    $effectiveAddress = $cleanAddress
                        ?? $linkedLocation?->formatted_address
                        ?? $oldAddress;

                    $cityHint = $linkedLocation?->city ?? ($geoSummary['city'] ?? null);
                    $zoneEval = $effectiveAddress !== null
                        ? $this->evaluateDeliveryZoneAndFee($workspace, $order, $effectiveAddress, $cityHint)
                        : null;

                    if ($zoneEval !== null && ($zoneEval['out_of_zone'] ?? false) === true) {
                        throw new OrderFeeConfirmationRequiredException(
                            orderNumber: (string) $order->order_number,
                            proposedAddress: (string) $effectiveAddress,
                            oldShippingAmount: Money::fromMinor(Money::minor((string) ($order->shipping_amount ?? '0'))),
                            newShippingAmount: Money::fromMinor(Money::minor((string) ($order->shipping_amount ?? '0'))),
                            newTotalAmount: (string) $order->total_amount,
                            currency: (string) ($order->currency ?: 'SAR'),
                            outOfZone: true,
                            zoneName: $zoneEval['zone_name'] ?? null,
                        );
                    }

                    $oldShippingMinor = Money::minor((string) ($order->shipping_amount ?? '0'));
                    $newShippingMinor = ($zoneEval !== null && ($zoneEval['matched'] ?? false) === true)
                        ? (int) $zoneEval['shipping_minor']
                        : $oldShippingMinor;
                    $shippingChanged = $newShippingMinor !== $oldShippingMinor;

                    $subtotalMinor = Money::minor((string) ($order->subtotal ?? '0'));
                    if ($subtotalMinor === 0 && $order->items->isNotEmpty()) {
                        foreach ($order->items as $it) {
                            $subtotalMinor += Money::minor((string) $it->total_amount);
                        }
                    }
                    $discountMinor = Money::minor((string) ($order->discount_amount ?? '0'));
                    $taxMinor = Money::minor((string) ($order->tax_amount ?? '0'));
                    $newTotalMinor = max(0, $subtotalMinor - $discountMinor + $taxMinor + $newShippingMinor);

                    if ($shippingChanged && ! $confirmFeeChange) {
                        throw new OrderFeeConfirmationRequiredException(
                            orderNumber: (string) $order->order_number,
                            proposedAddress: (string) $effectiveAddress,
                            oldShippingAmount: Money::fromMinor($oldShippingMinor),
                            newShippingAmount: Money::fromMinor($newShippingMinor),
                            newTotalAmount: Money::fromMinor($newTotalMinor),
                            currency: (string) ($order->currency ?: 'SAR'),
                            outOfZone: false,
                            zoneName: $zoneEval['zone_name'] ?? null,
                        );
                    }

                    if ($recipientName !== null && trim($recipientName) !== '') {
                        $metadata['recipient_name'] = trim($recipientName);
                    }
                    if ($phone !== null && trim($phone) !== '') {
                        $metadata['recipient_phone'] = $this->normalizePhone(trim($phone));
                    }
                    if ($effectiveAddress !== null) {
                        $metadata['shipping_address'] = $effectiveAddress;
                    }

                    $newDeliveryLocationId = $order->delivery_location_id;
                    if ($linkedLocation instanceof CustomerLocation) {
                        $newDeliveryLocationId = $linkedLocation->id;
                        $metadata['delivery_location'] = $linkedLocation->toSummaryArray();
                    } elseif ($cleanAddress !== null) {
                        // Text address update supersedes any stale GPS pin that belonged to the previous address.
                        if ($order->delivery_location_id !== null) {
                            $metadata['previous_delivery_location_id'] = (int) $order->delivery_location_id;
                            $newDeliveryLocationId = null;
                        }
                        unset($metadata['delivery_location']);
                    }

                    if (($zoneEval['zone_name'] ?? null) !== null) {
                        $metadata['delivery_zone'] = $zoneEval['zone_name'];
                    }

                    $history = is_array($metadata['address_update_history'] ?? null) ? $metadata['address_update_history'] : [];
                    $history[] = array_filter([
                        'from' => $oldAddress,
                        'to' => $effectiveAddress,
                        'delivery_location_id' => $newDeliveryLocationId,
                        'has_gps' => $linkedLocation !== null,
                        'shipping_changed' => $shippingChanged,
                        'updated_at' => now()->toIso8601String(),
                    ], static fn (mixed $v): bool => $v !== null);
                    $metadata['address_update_history'] = array_slice($history, -10);
                    $metadata['address_updated_at'] = now()->toIso8601String();
                    $metadata['last_modified_at'] = $metadata['address_updated_at'];

                    $replacedNotes = $this->replaceAddressInOrderNotes(
                        $notes !== null && trim($notes) !== '' ? trim($notes) : $order->notes,
                        $effectiveAddress,
                        isset($metadata['recipient_name']) && is_string($metadata['recipient_name']) ? $metadata['recipient_name'] : null,
                        isset($metadata['recipient_phone']) && is_string($metadata['recipient_phone']) ? $metadata['recipient_phone'] : null,
                        $metadata,
                    );

                    $orderUpdates = [
                        'delivery_location_id' => $newDeliveryLocationId,
                        'subtotal' => Money::fromMinor($subtotalMinor),
                        'shipping_amount' => Money::fromMinor($newShippingMinor),
                        'total_amount' => Money::fromMinor($newTotalMinor),
                        'notes' => $replacedNotes,
                        'metadata' => $metadata,
                    ];

                    if (is_string($order->review_reason) && (str_contains($order->review_reason, 'العنوان') || str_contains($order->review_reason, 'الموقع'))) {
                        $orderUpdates['review_reason'] = null;
                        $orderUpdates['review_required_action'] = null;
                    }

                    $order->forceFill($orderUpdates)->save();

                    // Do not overwrite primary customer address when modifying a separate recipient order.
                    if ($order->customer_id && empty($metadata['is_additional_order']) && $linkedLocation instanceof CustomerLocation) {
                        $custMeta = [];
                        $customer = Customer::withoutGlobalScopes()
                            ->where('workspace_id', $workspace->id)
                            ->find($order->customer_id);
                        if ($customer instanceof Customer) {
                            $custMeta = is_array($customer->metadata) ? $customer->metadata : [];
                            $custMeta['delivery_location'] = $linkedLocation->toSummaryArray();
                            $customer->forceFill([
                                'primary_location_id' => $linkedLocation->id,
                                'address' => $effectiveAddress ?: $customer->address,
                                'metadata' => $custMeta,
                            ])->save();
                        }
                    }

                    if ($shippingChanged) {
                        $this->aggregateService->refreshOrderAggregates($workspace->id, (int) $order->customer_id);
                    }

                    $freshOrder = $order->fresh()->load(['items', 'deliveryLocation']);

                    DB::afterCommit(function () use ($workspace, $conversation, $freshOrder, $effectiveAddress, $linkedLocation): void {
                        $this->syncConversationMemory($workspace, $conversation, $freshOrder, $effectiveAddress, $linkedLocation);
                        app(InboxRealtimePublisher::class)->publishOrderUpdated($freshOrder, $conversation, 'address_updated');
                    });

                    Log::info('order.address_update.result', array_merge(CorrelationContext::logContext(), [
                        'workspace_id' => $workspace->id,
                        'conversation_id' => $conversation->id,
                        'order_id' => $freshOrder->id,
                        'order_number' => $freshOrder->order_number,
                        'success' => true,
                        'delivery_location_id' => $freshOrder->delivery_location_id,
                        'shipping_changed' => $shippingChanged,
                        'shipping_amount' => (string) $freshOrder->shipping_amount,
                        'total_amount' => (string) $freshOrder->total_amount,
                    ]));

                    return [
                        'order' => $freshOrder,
                        'old_address' => $oldAddress,
                        'new_address' => $effectiveAddress,
                        'shipping_changed' => $shippingChanged,
                        'old_shipping_amount' => Money::fromMinor($oldShippingMinor),
                        'new_shipping_amount' => Money::fromMinor($newShippingMinor),
                    ];
                });
            } catch (Throwable $exception) {
                Log::warning('order.address_update.result', array_merge(CorrelationContext::logContext(), [
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'order_number' => $orderNumber,
                    'success' => false,
                    'failure_type' => $exception::class,
                    'reason' => $exception->getMessage(),
                ]));

                throw $exception;
            }
        });
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
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $deliveryLocationId = null,
        ?string $orderHint = null,
        bool $confirmFeeChange = false,
    ): Order {
        $normalizedMode = in_array($mode, ['add_items', 'replace_items', 'update_details'], true) ? $mode : 'add_items';

        $hasAddressMutation = ($address !== null && trim($address) !== '')
            || ($latitude !== null && $longitude !== null)
            || $deliveryLocationId !== null;

        if ($items === [] && $hasAddressMutation) {
            $result = $this->updateOrderAddress(
                workspace: $workspace,
                conversation: $conversation,
                orderNumber: $orderNumber,
                address: $address,
                latitude: $latitude,
                longitude: $longitude,
                deliveryLocationId: $deliveryLocationId,
                recipientName: $customerName,
                phone: $phone,
                notes: $notes,
                orderHint: $orderHint,
                confirmFeeChange: $confirmFeeChange,
            );

            return $result['order'];
        }

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
            $latitude,
            $longitude,
            $deliveryLocationId,
            $orderHint,
            $confirmFeeChange,
            $hasAddressMutation,
        ): Order {
            $updatedOrder = DB::transaction(function () use (
                $workspace,
                $conversation,
                $orderNumber,
                $normalizedMode,
                $items,
                $customerName,
                $phone,
                $address,
                $notes,
                $orderHint,
            ): Order {
                $order = $this->resolveOwnedOrderForAction(
                    workspace: $workspace,
                    conversation: $conversation,
                    orderNumber: $orderNumber,
                    orderHint: $orderHint,
                    forCancellation: false,
                    lock: true,
                );
                if (! $order instanceof Order) {
                    throw new InvalidArgumentException('لم يتم العثور على طلب نشط مرتبط بهذه المحادثة لتعديله.');
                }
                if ($order->status === 'cancelled') {
                    throw new InvalidArgumentException('لا يمكن تعديل طلب ملغى.');
                }
                if ($order->payment_status === 'paid' || $order->fulfillment_status === 'fulfilled') {
                    throw new InvalidArgumentException('الطلب مدفوع أو مكتمل التنفيذ، يرجى التحويل للدعم البشري لتعديله.');
                }

                $order->loadMissing(['items', 'deliveryLocation']);
                $metadata = is_array($order->metadata) ? $order->metadata : [];
                $currency = $order->currency ?: 'SAR';

                if ($customerName !== null && $customerName !== '') {
                    $metadata['recipient_name'] = $customerName;
                }
                if ($phone !== null && $phone !== '') {
                    $metadata['recipient_phone'] = $this->normalizePhone($phone);
                }
                $metadata['last_modified_at'] = now()->toIso8601String();

                $workspaceHasCatalog = ChatCatalogItem::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('workspace_id', $workspace->id)
                    ->exists();

                if ($items !== [] && $normalizedMode !== 'update_details') {
                    $incomingLines = [];
                    foreach ($items as $itemData) {
                        if (! is_array($itemData)) {
                            continue;
                        }
                        $incomingLines[] = $this->resolveItemLine($workspace, $itemData, $currency, $workspaceHasCatalog);
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

                $subtotalMinor = 0;
                foreach ($freshItems as $freshItem) {
                    $subtotalMinor += Money::minor((string) $freshItem->total_amount);
                    $currency = $freshItem->currency ?: $currency;
                }

                $shippingMinor = Money::minor((string) ($order->shipping_amount ?? '0'));
                $discountMinor = Money::minor((string) ($order->discount_amount ?? '0'));
                $taxMinor = Money::minor((string) ($order->tax_amount ?? '0'));
                $totalMinor = max(0, $subtotalMinor - $discountMinor + $taxMinor + $shippingMinor);

                $subtotalAmount = Money::fromMinor($subtotalMinor);
                $totalAmount = Money::fromMinor($totalMinor);
                $updatedNotes = $notes !== null && trim($notes) !== ''
                    ? trim($notes)
                    : $order->notes;

                $order->update([
                    'currency' => $currency,
                    'subtotal' => $subtotalAmount,
                    'total_amount' => $totalAmount,
                    'notes' => $updatedNotes,
                    'metadata' => $metadata,
                ]);

                $this->aggregateService->refreshOrderAggregates($workspace->id, (int) $order->customer_id);

                $freshOrder = $order->fresh()->load(['items', 'deliveryLocation']);

                DB::afterCommit(function () use ($workspace, $conversation, $freshOrder, $hasAddressMutation): void {
                    if (! $hasAddressMutation) {
                        $this->syncConversationMemory($workspace, $conversation, $freshOrder);
                        app(InboxRealtimePublisher::class)->publishOrderUpdated($freshOrder, $conversation, 'updated');
                    }
                });

                return $freshOrder;
            });

            if ($hasAddressMutation) {
                $addressResult = $this->updateOrderAddress(
                    workspace: $workspace,
                    conversation: $conversation,
                    orderNumber: $updatedOrder->order_number,
                    address: $address,
                    latitude: $latitude,
                    longitude: $longitude,
                    deliveryLocationId: $deliveryLocationId,
                    recipientName: $customerName,
                    phone: $phone,
                    notes: $notes,
                    confirmFeeChange: $confirmFeeChange,
                );

                return $addressResult['order'];
            }

            return $updatedOrder;
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
        ?string $orderHint = null,
    ): Order {
        Log::info('order.cancel.requested', array_merge(CorrelationContext::logContext(), [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'order_number' => $orderNumber,
            'has_order_hint' => $orderHint !== null && trim($orderHint) !== '',
        ]));

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $orderNumber,
            $reason,
            $orderHint,
        ): Order {
            try {
                return DB::transaction(function () use (
                    $workspace,
                    $conversation,
                    $orderNumber,
                    $reason,
                    $orderHint,
                ): Order {
                    $order = $this->resolveOwnedOrderForAction(
                        workspace: $workspace,
                        conversation: $conversation,
                        orderNumber: $orderNumber,
                        orderHint: $orderHint,
                        forCancellation: true,
                        lock: true,
                    );
                    if (! $order instanceof Order) {
                        throw new InvalidArgumentException('لم يتم العثور على طلب نشط مرتبط بهذه المحادثة لإلغائه.');
                    }
                    if ($order->status === 'cancelled') {
                        throw new InvalidArgumentException('الطلب ملغى بالفعل.');
                    }
                    if ($order->payment_status === 'paid'
                        || $order->fulfillment_status === 'fulfilled'
                        || in_array((string) $order->shipping_status, ['shipped', 'delivered', 'returned'], true)) {
                        throw new InvalidArgumentException('الطلب مدفوع أو تم تنفيذه/شحنه، يرجى تحويل العميل للدعم البشري لإلغائه أو استرجاعه.');
                    }

                    $metadata = is_array($order->metadata) ? $order->metadata : [];
                    if ($reason !== null && $reason !== '') {
                        $metadata['cancellation_reason'] = $reason;
                    }
                    $metadata['cancelled_by'] = 'ai_employee';
                    $metadata['last_modified_at'] = now()->toIso8601String();

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

                    $freshOrder = $order->fresh()->load(['items', 'deliveryLocation']);

                    DB::afterCommit(function () use ($workspace, $conversation, $freshOrder): void {
                        $this->syncConversationMemory($workspace, $conversation, $freshOrder);
                        app(InboxRealtimePublisher::class)->publishOrderUpdated($freshOrder, $conversation, 'cancelled');
                    });

                    Log::info('order.cancel.result', array_merge(CorrelationContext::logContext(), [
                        'workspace_id' => $workspace->id,
                        'conversation_id' => $conversation->id,
                        'order_id' => $freshOrder->id,
                        'order_number' => $freshOrder->order_number,
                        'success' => true,
                        'status' => $freshOrder->status,
                    ]));

                    return $freshOrder;
                });
            } catch (Throwable $exception) {
                Log::warning('order.cancel.result', array_merge(CorrelationContext::logContext(), [
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'order_number' => $orderNumber,
                    'success' => false,
                    'failure_type' => $exception::class,
                    'reason' => $exception->getMessage(),
                ]));

                throw $exception;
            }
        });
    }

    /**
     * Resolve target order with strict multi-tenant & ownership verification.
     * Refuses to silently pick latest order when multiple active orders exist without unambiguous selection.
     */
    private function resolveOwnedOrderForAction(
        Workspace $workspace,
        Conversation $conversation,
        ?string $orderNumber,
        ?string $orderHint = null,
        bool $forCancellation = false,
        bool $lock = true,
        bool $activeOnly = true,
    ): ?Order {
        $customerId = $conversation->customer_id !== null ? (int) $conversation->customer_id : null;
        $conversationId = $conversation->id > 0 ? (int) $conversation->id : null;

        if (($customerId === null || $customerId <= 0) && ($conversationId === null || $conversationId <= 0)) {
            Log::warning('order.ownership_check', [
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'owned' => false,
                'reason' => 'unverified_conversation_customer',
            ]);

            return null;
        }

        $query = $this->ownedOrdersQuery($workspace, $conversation)
            ->with(['items', 'deliveryLocation'])
            ->orderByDesc('id');

        if ($activeOnly) {
            $query->where('status', '!=', 'cancelled')
                ->where('status', '!=', 'completed')
                ->where('fulfillment_status', '!=', 'fulfilled');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $cleanOrderNumber = $orderNumber !== null ? trim($orderNumber) : '';
        if ($cleanOrderNumber !== '') {
            $this->applyOrderNumberFilter($query, $cleanOrderNumber);
            $matched = $query->first();

            if ($matched instanceof Order) {
                Log::info('order.ownership_check', [
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customerId,
                    'order_id' => $matched->id,
                    'order_number' => $matched->order_number,
                    'owned' => true,
                ]);

                return $matched;
            }

            // Explicit lookup failed within owned scope — check whether an existing order belongs to someone else for diagnostic logs only.
            $foreignOrCancelled = Order::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where(fn ($q) => $this->applyOrderNumberFilter($q, $cleanOrderNumber))
                ->first(['id', 'customer_id', 'conversation_id', 'status']);

            if ($foreignOrCancelled instanceof Order) {
                $isSameOwner = ($conversationId !== null && (int) $foreignOrCancelled->conversation_id === $conversationId)
                    || ($customerId !== null && (int) $foreignOrCancelled->customer_id === $customerId);

                if ($isSameOwner && $activeOnly) {
                    if ($foreignOrCancelled->status === 'cancelled') {
                        throw new InvalidArgumentException('الطلب رقم '.$cleanOrderNumber.' ملغى بالفعل.');
                    }
                    if ($foreignOrCancelled->status === 'completed') {
                        throw new InvalidArgumentException('الطلب رقم '.$cleanOrderNumber.' مكتمل بالفعل ولا يمكن تعديله آلياً.');
                    }
                }

                Log::warning('order.ownership_check', [
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customerId,
                    'order_number' => $cleanOrderNumber,
                    'owned' => false,
                    'reason' => $isSameOwner ? 'order_not_modifiable' : 'cross_customer_denied',
                ]);

                throw new InvalidArgumentException('لا يمكن الوصول إلى هذا الطلب أو أنه لا يخص هذه المحادثة.');
            }

            Log::warning('order.ownership_check', [
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customerId,
                'order_number' => $cleanOrderNumber,
                'owned' => false,
                'reason' => 'not_found_or_other_workspace',
            ]);

            return null;
        }

        $candidates = $query->limit(15)->get();
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            /** @var Order $only */
            $only = $candidates->first();
            Log::info('order.ownership_check', [
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customerId,
                'order_id' => $only->id,
                'order_number' => $only->order_number,
                'owned' => true,
            ]);

            return $only;
        }

        // Multiple active orders exist: refuse to silently pick latest unless hint uniquely resolves a specific order without ambiguous relative phrasing.
        $trimmedHint = trim((string) ($orderHint ?? ''));
        $isAmbiguousRelative = $trimmedHint === ''
            || preg_match('/الآخر|الاخر|الثاني|التاني|القديم|السابق|غيره|other|another|previous|second/iu', $trimmedHint) === 1;

        if (! $isAmbiguousRelative) {
            $needle = mb_strtolower($trimmedHint);
            $matchedCandidates = $candidates->filter(function (Order $cand) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) $cand->order_number,
                    (string) ($cand->placed_at?->format('Y-m-d') ?? ''),
                    (string) data_get($cand->metadata, 'shipping_address', ''),
                    $cand->items->pluck('product_name')->implode(' '),
                ]));

                return str_contains($haystack, $needle);
            })->values();

            if ($matchedCandidates->count() === 1) {
                /** @var Order $uniqueMatch */
                $uniqueMatch = $matchedCandidates->first();

                return $uniqueMatch;
            }
        }

        $serializedCandidates = $candidates
            ->map(fn (Order $c): array => $this->serializeOrderSummary($c))
            ->values()
            ->all();

        throw new AmbiguousOrderSelectionException(
            candidates: $serializedCandidates,
            message: $forCancellation
                ? 'لديك عدة طلبات نشطة. يرجى تحديد رقم الطلب الذي ترغب في إلغائه.'
                : 'لديك عدة طلبات نشطة. يرجى تحديد رقم الطلب الذي ترغب في تعديله.',
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    private function ownedOrdersQuery(Workspace $workspace, Conversation $conversation)
    {
        $customerId = $conversation->customer_id !== null ? (int) $conversation->customer_id : null;
        $conversationId = $conversation->id > 0 ? (int) $conversation->id : null;

        return Order::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where(function ($ownerQuery) use ($customerId, $conversationId): void {
                if ($conversationId !== null && $conversationId > 0) {
                    $ownerQuery->where('conversation_id', $conversationId);
                }
                if ($customerId !== null && $customerId > 0) {
                    $method = ($conversationId !== null && $conversationId > 0) ? 'orWhere' : 'where';
                    $ownerQuery->{$method}('customer_id', $customerId);
                    // Include separate-recipient additional orders placed from this customer's account
                    $ownerQuery->orWhere('metadata->ordered_by_customer_id', $customerId);
                }
            });
    }

    private function applyOrderNumberFilter($query, string $orderNumber): void
    {
        $clean = trim($orderNumber);
        $normalized = ltrim($clean, '#');
        $query->where(function ($numQuery) use ($clean, $normalized): void {
            $numQuery->where('order_number', $clean)
                ->orWhere('order_number', $normalized)
                ->orWhere('order_number', '#'.$normalized);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOrderSummary(Order $order): array
    {
        $status = (string) $order->status;
        $paymentStatus = (string) ($order->payment_status ?? 'pending');
        $fulfillmentStatus = (string) ($order->fulfillment_status ?? 'unfulfilled');
        $shippingStatus = (string) ($order->shipping_status ?? 'not_shipped');

        $blockReason = match (true) {
            $status === 'cancelled' => 'الطلب ملغى بالفعل',
            $status === 'completed' => 'الطلب مكتمل ولا يمكن تعديله أو إلغاؤه آلياً',
            $paymentStatus === 'paid' => 'الطلب مدفوع ويتطلب تدخل الدعم البشري',
            $fulfillmentStatus === 'fulfilled' => 'الطلب مكتمل التجهيز ولا يمكن تعديله آلياً',
            in_array($shippingStatus, ['shipped', 'delivered', 'returned'], true) => 'تم شحن الطلب بالفعل',
            default => null,
        };

        $items = $order->relationLoaded('items')
            ? $order->items->map(static fn ($item): array => [
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'item_type' => $item->item_type,
                'quantity' => (int) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'total_amount' => (string) $item->total_amount,
            ])->values()->all()
            : [];

        $location = $order->relationLoaded('deliveryLocation') && $order->deliveryLocation instanceof CustomerLocation
            ? $order->deliveryLocation->toSummaryArray()
            : (is_array(data_get($order->metadata, 'delivery_location')) ? data_get($order->metadata, 'delivery_location') : null);

        return [
            'id' => (int) $order->id,
            'order_number' => $order->order_number,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'shipping_status' => $shippingStatus,
            'is_active' => $status !== 'cancelled' && $status !== 'completed' && $fulfillmentStatus !== 'fulfilled',
            'is_cancellable' => $blockReason === null,
            'can_update_address' => $status !== 'cancelled'
                && $status !== 'completed'
                && $fulfillmentStatus !== 'fulfilled'
                && ! in_array($shippingStatus, ['shipped', 'delivered', 'returned'], true),
            'cannot_cancel_reason' => $blockReason,
            'subtotal' => (string) ($order->subtotal ?? $order->total_amount),
            'shipping_amount' => (string) ($order->shipping_amount ?? '0.00'),
            'total_amount' => (string) $order->total_amount,
            'currency' => $order->currency ?: 'SAR',
            'shipping_address' => data_get($order->metadata, 'shipping_address'),
            'delivery_location' => $location,
            'coordinates' => is_array($location) && isset($location['latitude'], $location['longitude'])
                ? ['latitude' => $location['latitude'], 'longitude' => $location['longitude']]
                : null,
            'recipient_name' => data_get($order->metadata, 'recipient_name'),
            'recipient_phone' => data_get($order->metadata, 'recipient_phone'),
            'is_additional_order' => (bool) data_get($order->metadata, 'is_additional_order', false),
            'notes' => $order->notes,
            'placed_at' => optional($order->placed_at ?? $order->created_at)?->toIso8601String(),
            'placed_date' => optional($order->placed_at ?? $order->created_at)?->format('Y-m-d H:i'),
            'items' => $items,
        ];
    }

    /**
     * Evaluate workspace delivery coverage and zone fee for an address.
     *
     * @return array{matched: bool, out_of_zone: bool, shipping_minor: int, zone_name: ?string}|null
     */
    private function evaluateDeliveryZoneAndFee(
        Workspace $workspace,
        ?Order $order,
        string $address,
        ?string $cityHint = null,
    ): ?array {
        unset($order);

        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $aiSetting = AiSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        $biz = is_array($aiSetting?->business_information) ? $aiSetting->business_information : [];
        $catalog = ChatCatalog::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        $policy = is_array($catalog?->customer_service_policy) ? $catalog->customer_service_policy : [];

        $shippingConfig = array_merge(
            is_array($policy['shipping'] ?? null) ? $policy['shipping'] : [],
            is_array($biz['shipping'] ?? null) ? $biz['shipping'] : [],
            is_array($settings['shipping'] ?? null) ? $settings['shipping'] : [],
            is_array($settings['delivery'] ?? null) ? $settings['delivery'] : [],
        );

        if ($shippingConfig === []) {
            return null;
        }

        $haystack = mb_strtolower(trim($address.' '.(string) $cityHint));

        $excluded = $shippingConfig['excluded_cities'] ?? $shippingConfig['blocked_cities'] ?? [];
        if (is_array($excluded)) {
            foreach ($excluded as $blockedCity) {
                if (is_string($blockedCity) && trim($blockedCity) !== '' && str_contains($haystack, mb_strtolower(trim($blockedCity)))) {
                    return [
                        'matched' => true,
                        'out_of_zone' => true,
                        'shipping_minor' => 0,
                        'zone_name' => trim($blockedCity),
                    ];
                }
            }
        }

        $zones = $shippingConfig['delivery_zones'] ?? $shippingConfig['zones'] ?? $shippingConfig['fee_by_city'] ?? [];
        if (is_array($zones)) {
            foreach ($zones as $key => $zoneData) {
                $zoneName = is_string($key) ? trim($key) : null;
                $fee = null;
                $available = true;
                $keywords = [];

                if (is_numeric($zoneData)) {
                    $fee = $zoneData;
                    if ($zoneName !== null) {
                        $keywords[] = $zoneName;
                    }
                } elseif (is_array($zoneData)) {
                    $zoneName = trim((string) ($zoneData['name'] ?? $zoneData['city'] ?? $zoneName ?? ''));
                    $fee = $zoneData['fee'] ?? $zoneData['cost'] ?? $zoneData['shipping_cost'] ?? null;
                    $available = ($zoneData['available'] ?? true) !== false;
                    if ($zoneName !== '') {
                        $keywords[] = $zoneName;
                    }
                    foreach ((array) ($zoneData['keywords'] ?? []) as $kw) {
                        if (is_string($kw) && trim($kw) !== '') {
                            $keywords[] = trim($kw);
                        }
                    }
                }

                foreach ($keywords as $kw) {
                    if ($kw !== '' && str_contains($haystack, mb_strtolower($kw))) {
                        if (! $available) {
                            return [
                                'matched' => true,
                                'out_of_zone' => true,
                                'shipping_minor' => 0,
                                'zone_name' => $zoneName !== '' ? $zoneName : $kw,
                            ];
                        }

                        if ($fee !== null && is_numeric($fee)) {
                            return [
                                'matched' => true,
                                'out_of_zone' => false,
                                'shipping_minor' => Money::minor((string) $fee),
                                'zone_name' => $zoneName !== '' ? $zoneName : $kw,
                            ];
                        }
                    }
                }
            }
        }

        if (isset($shippingConfig['default_fee']) && is_numeric($shippingConfig['default_fee'])) {
            return [
                'matched' => true,
                'out_of_zone' => false,
                'shipping_minor' => Money::minor((string) $shippingConfig['default_fee']),
                'zone_name' => null,
            ];
        }

        return null;
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
    private function replaceAddressInOrderNotes(
        ?string $baseNotes,
        ?string $newAddress,
        ?string $recipientName,
        ?string $recipientPhone,
        array $metadata,
    ): ?string {
        $rawLines = preg_split("/\r\n|\n|\r/", (string) ($baseNotes ?? '')) ?: [];
        $keptLines = [];
        foreach ($rawLines as $rawLine) {
            $trimmedLine = trim($rawLine);
            if ($trimmedLine === '') {
                continue;
            }
            if (preg_match('/^العنوان\s*:/u', $trimmedLine) === 1) {
                continue;
            }
            $keptLines[] = $trimmedLine;
        }

        if ($newAddress !== null && trim($newAddress) !== '') {
            $keptLines[] = 'العنوان: '.trim($newAddress);
        }

        if (! empty($metadata['is_additional_order'])) {
            $joined = implode("\n", $keptLines);
            if ($recipientName !== null && trim($recipientName) !== '' && ! str_contains($joined, trim($recipientName))) {
                $keptLines[] = 'المستلم: '.trim($recipientName);
            }
            if ($recipientPhone !== null && trim($recipientPhone) !== '' && ! str_contains($joined, trim($recipientPhone))) {
                $keptLines[] = 'جوال المستلم: '.trim($recipientPhone);
            }
        }

        return $keptLines !== [] ? implode("\n", $keptLines) : null;
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
        return $this->replaceAddressInOrderNotes($baseNotes, $address, $recipientName, $recipientPhone, $metadata);
    }

    private function syncConversationMemory(
        Workspace $workspace,
        Conversation $conversation,
        Order $order,
        ?string $address = null,
        ?CustomerLocation $location = null,
    ): void {
        try {
            $extra = [
                'order_status' => (string) $order->status,
            ];
            if ($location instanceof CustomerLocation) {
                $extra['location_id'] = (int) $location->id;
                $extra['location_captured'] = true;
                $extra['location_status'] = $location->verification_status;
                if ($location->city) {
                    $extra['city'] = $location->city;
                }
            }
            app(ConversationFactsMemory::class)->syncOrderState(
                workspaceId: (int) $workspace->id,
                conversationId: (int) $conversation->id,
                orderNumber: $order->order_number,
                address: $address,
                extraFacts: $extra,
            );
        } catch (Throwable) {
            // Non-blocking memory sync
        }
    }
}
