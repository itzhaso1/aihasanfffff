<?php

namespace App\Services\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\Commerce\CommerceReservationStatus;
use App\Events\CommerceInventoryReservationCreated;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemDigitalProfile;
use App\Models\ChatCatalogItemVariant;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceInventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Chat\ChatCatalogDigitalInventoryService;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Tracks commerce reservations without creating a second Finance/POS stock
 * system. Chat Catalog finite stock is reserved through reservation rows;
 * digital units use their existing authoritative Digital Inventory service.
 */
final class CommerceInventoryReservationService
{
    public function __construct(
        private readonly ChatCatalogDigitalInventoryService $digitalInventory,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return list<CommerceInventoryReservation>
     */
    public function reserveForOrderLine(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $inventoryPolicy,
    ): array {
        $this->assertOwnership($workspace, $order, $orderItem, $item, $variant);

        return match ($inventoryPolicy) {
            ChatCatalogInventoryPolicy::DigitalPool->value,
            ChatCatalogInventoryPolicy::FileAsset->value => $this->reserveDigital(
                $workspace,
                $order,
                $orderItem,
                $item,
                $variant,
                $inventoryPolicy,
            ),
            ChatCatalogInventoryPolicy::FiniteStock->value => [
                $this->reservePhysical($workspace, $order, $orderItem, $item, $variant, $inventoryPolicy),
            ],
            ChatCatalogInventoryPolicy::ServiceCapacity->value => $this->reserveServiceCapacity(
                $workspace,
                $order,
                $orderItem,
                $item,
                $variant,
                $inventoryPolicy,
            ),
            default => [
                $this->reservePolicyOnly($workspace, $order, $orderItem, $item, $variant, $inventoryPolicy),
            ],
        };
    }

    public function releaseForOrder(Order $order): void
    {
        $reservations = CommerceInventoryReservation::withoutGlobalScopes()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_id', $order->id)
            ->where('status', CommerceReservationStatus::Reserved->value)
            ->with('digitalUnit')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if ($reservation->reservation_kind === 'digital' && $reservation->digitalUnit) {
                try {
                    $this->digitalInventory->releaseReservation(
                        $reservation->digitalUnit,
                        $reservation->reservation_key,
                    );
                } catch (\InvalidArgumentException) {
                    // Expired/previously released digital reservations are safe
                    // to mark released locally; no secret is ever read here.
                }
            }

            $reservation->update([
                'status' => CommerceReservationStatus::Released,
                'expires_at' => null,
            ]);
            $this->auditReservation('released', $reservation);
        }
    }

    public function consumeForOrder(Order $order): void
    {
        $reservations = CommerceInventoryReservation::withoutGlobalScopes()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_id', $order->id)
            ->where('status', CommerceReservationStatus::Reserved->value)
            ->with('digitalUnit')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if ($reservation->reservation_kind === 'digital' && $reservation->digitalUnit) {
                $this->digitalInventory->consumeReserved(
                    $reservation->digitalUnit,
                    $reservation->reservation_key,
                );
            }
            $reservation->update([
                'status' => CommerceReservationStatus::Consumed,
                'expires_at' => null,
            ]);
            $this->auditReservation('consumed', $reservation);
        }
    }

    private function reservePhysical(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $policy,
    ): CommerceInventoryReservation {
        return $this->firstOrCreateReservation(
            $workspace,
            $order,
            $orderItem,
            $item,
            $variant,
            kind: 'physical',
            key: "commerce:{$order->id}:{$orderItem->id}:physical",
            quantity: (int) $orderItem->quantity,
            expiresAt: $this->reservationExpiry($workspace, $order),
            metadata: ['inventory_policy' => $policy],
        );
    }

    /**
     * @return list<CommerceInventoryReservation>
     */
    private function reserveDigital(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $policy,
    ): array {
        $pool = ChatCatalogDigitalInventoryPool::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('chat_catalog_item_id', $item->id)
            ->when(
                $variant,
                fn ($query) => $query->where('chat_catalog_item_variant_id', $variant->id),
                fn ($query) => $query->whereNull('chat_catalog_item_variant_id'),
            )
            ->lockForUpdate()
            ->first();
        if (! $pool) {
            throw new RuntimeException('Digital inventory pool is unavailable.');
        }
        $profile = ChatCatalogItemDigitalProfile::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('chat_catalog_item_id', $item->id)
            ->when(
                $variant,
                fn ($query) => $query->where('chat_catalog_item_variant_id', $variant->id),
                fn ($query) => $query->whereNull('chat_catalog_item_variant_id'),
            )
            ->first();
        if (! $profile) {
            throw new RuntimeException('Digital inventory profile is unavailable.');
        }

        $reservations = [];
        for ($index = 1; $index <= (int) $orderItem->quantity; $index++) {
            $key = "commerce:{$order->id}:{$orderItem->id}:digital:{$index}";
            $existing = CommerceInventoryReservation::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('order_item_id', $orderItem->id)
                ->where('reservation_kind', 'digital')
                ->where('reservation_key', $key)
                ->first();
            if ($existing) {
                $reservations[] = $existing;

                continue;
            }

            $unit = $this->digitalInventory->reserveUnit(
                $pool,
                $key,
                unitType: $profile->unit_type,
            );
            $reservations[] = $this->firstOrCreateReservation(
                $workspace,
                $order,
                $orderItem,
                $item,
                $variant,
                kind: 'digital',
                key: $key,
                quantity: 1,
                digitalUnitId: (int) $unit->id,
                expiresAt: $unit->reserved_until,
                metadata: [
                    'inventory_policy' => $policy,
                    'unit_type' => $unit->unit_type?->value,
                ],
            );
        }

        return $reservations;
    }

    /**
     * @return list<CommerceInventoryReservation>
     */
    private function reserveServiceCapacity(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $policy,
    ): array {
        if (($variant?->stock ?? $item->stock) !== null) {
            return [$this->reservePhysical($workspace, $order, $orderItem, $item, $variant, $policy)];
        }

        return [$this->reservePolicyOnly($workspace, $order, $orderItem, $item, $variant, $policy)];
    }

    private function reservePolicyOnly(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $policy,
    ): CommerceInventoryReservation {
        return $this->firstOrCreateReservation(
            $workspace,
            $order,
            $orderItem,
            $item,
            $variant,
            kind: 'policy',
            key: "commerce:{$order->id}:{$orderItem->id}:policy",
            quantity: (int) $orderItem->quantity,
            expiresAt: $this->reservationExpiry($workspace, $order),
            metadata: [
                'inventory_policy' => $policy,
                'booking_required' => (bool) data_get($item->policies, 'booking_required', false),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function firstOrCreateReservation(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $kind,
        string $key,
        int $quantity,
        ?int $digitalUnitId = null,
        ?\DateTimeInterface $expiresAt = null,
        array $metadata = [],
    ): CommerceInventoryReservation {
        $existing = CommerceInventoryReservation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('order_item_id', $orderItem->id)
            ->where('reservation_kind', $kind)
            ->where('reservation_key', $key)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            $reservation = CommerceInventoryReservation::query()->create([
                'workspace_id' => $workspace->id,
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => $variant?->id,
                'chat_catalog_digital_inventory_unit_id' => $digitalUnitId,
                'reservation_kind' => $kind,
                'status' => CommerceReservationStatus::Reserved,
                'reservation_key' => $key,
                'quantity' => $quantity,
                'expires_at' => $expiresAt,
                'metadata' => $metadata,
            ]);
        } catch (UniqueConstraintViolationException) {
            $reservation = CommerceInventoryReservation::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('order_item_id', $orderItem->id)
                ->where('reservation_kind', $kind)
                ->where('reservation_key', $key)
                ->firstOrFail();
        }

        $this->auditReservation('reserved', $reservation);
        event(new CommerceInventoryReservationCreated($reservation));

        return $reservation;
    }

    private function assertOwnership(
        Workspace $workspace,
        Order $order,
        OrderItem $orderItem,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
    ): void {
        if ((int) $order->workspace_id !== (int) $workspace->id
            || (int) $orderItem->workspace_id !== (int) $workspace->id
            || (int) $orderItem->order_id !== (int) $order->id
            || (int) $item->workspace_id !== (int) $workspace->id
            || ($variant && ((int) $variant->workspace_id !== (int) $workspace->id
                || (int) $variant->chat_catalog_item_id !== (int) $item->id))) {
            throw new RuntimeException('Cross-workspace inventory reservation rejected.');
        }
    }

    private function auditReservation(string $action, CommerceInventoryReservation $reservation): void
    {
        $this->audit->log(
            action: 'commerce.inventory_reservation.'.$action,
            entityType: CommerceInventoryReservation::class,
            entityId: (int) $reservation->id,
            meta: [
                'order_id' => (int) $reservation->order_id,
                'order_item_id' => (int) $reservation->order_item_id,
                'kind' => $reservation->reservation_kind,
                'quantity' => (int) $reservation->quantity,
            ],
            workspaceId: (int) $reservation->workspace_id,
        );
    }

    private function reservationExpiry(Workspace $workspace, Order $order): ?\DateTimeInterface
    {
        if (! $order->commerce_cart_id) {
            return null;
        }

        return CommerceCart::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($order->commerce_cart_id)
            ->first(['id', 'expires_at'])
            ?->expires_at;
    }
}
