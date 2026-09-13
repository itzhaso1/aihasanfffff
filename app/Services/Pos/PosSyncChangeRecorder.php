<?php

namespace App\Services\Pos;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only workspace change log. Row id is the monotonic sync cursor.
 */
class PosSyncChangeRecorder
{
    public const ENTITY_PRODUCT = 'product';

    public const ENTITY_CATEGORY = 'category';

    public const ENTITY_TABLE = 'table';

    public const ENTITY_ORDER = 'order';

    public const ENTITY_CUSTOMER = 'customer';

    public const ENTITY_STOCK = 'stock';

    public function record(
        string $entityType,
        string $operation,
        Model $model,
        ?string $originDeviceId = null,
    ): PosSyncChange {
        $workspaceId = (int) $model->getAttribute('workspace_id');
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('workspace_id required to record sync change');
        }

        return app(WorkspaceContext::class)->runForId($workspaceId, function () use ($entityType, $operation, $model, $originDeviceId, $workspaceId): PosSyncChange {
        $entityId = (int) $model->getKey();
        $payload = match ($operation) {
            'delete' => [
                'id' => $entityId,
                'deleted' => true,
                'client_reference' => $model->getAttribute('client_reference'),
            ],
            default => $this->snapshot($entityType, $model),
        };

        return PosSyncChange::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId > 0 ? $entityId : null,
            'operation' => $operation,
            'payload' => $payload,
            'origin_device_id' => $originDeviceId,
            'created_at' => now(),
        ]);
        });
    }

    /**
     * Record an order update from an order-item mutation (full order snapshot).
     */
    public function recordOrderSnapshot(Order $order, string $operation = 'update', ?string $originDeviceId = null): PosSyncChange
    {
        return $this->record(self::ENTITY_ORDER, $operation, $order, $originDeviceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $entityType, Model $model): array
    {
        return match ($entityType) {
            self::ENTITY_PRODUCT => $this->productPayload($model instanceof PosMenuItem ? $model : null),
            self::ENTITY_CATEGORY => $this->categoryPayload($model instanceof PosItemCategory ? $model : null),
            self::ENTITY_TABLE => $this->tablePayload($model instanceof DiningTable ? $model : null),
            self::ENTITY_ORDER => $this->orderPayload($model instanceof Order ? $model : null),
            self::ENTITY_CUSTOMER => $this->customerPayload($model instanceof Customer ? $model : null),
            self::ENTITY_STOCK => $this->stockPayload($model instanceof InventoryMovement ? $model : null),
            default => [
                'id' => $model->getKey(),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(?PosMenuItem $item): array
    {
        if (! $item) {
            return [];
        }

        $item->loadMissing('product');

        return [
            'id' => $item->id,
            'pos_item_category_id' => $item->pos_item_category_id,
            'product_id' => $item->product_id,
            'name' => $item->name,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'item_type' => $item->item_type,
            'description' => $item->description,
            'price' => (float) $item->price,
            'currency' => $item->currency,
            'is_active' => (bool) $item->is_active,
            'sort_order' => (int) ($item->sort_order ?? 0),
            'stock' => $item->product?->stock !== null ? (int) $item->product->stock : null,
            'updated_at' => optional($item->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPayload(?PosItemCategory $category): array
    {
        if (! $category) {
            return [];
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'is_active' => (bool) $category->is_active,
            'sort_order' => (int) ($category->sort_order ?? 0),
            'updated_at' => optional($category->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tablePayload(?DiningTable $table): array
    {
        if (! $table) {
            return [];
        }

        $table->unsetRelation('sessions');
        $table->load([
            'sessions' => fn ($query) => $query
                ->where('status', 'open')
                ->latest('id')
                ->limit(1),
        ]);
        $open = $table->sessions->first();

        return [
            'id' => $table->id,
            'name' => $table->name,
            'status' => $table->status,
            'qr_token' => $table->qr_token,
            'session_id' => $open?->id,
            'session_open' => $open !== null,
            'opened_at' => optional($open?->opened_at)?->toIso8601String(),
            'updated_at' => optional($table->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(?Order $order): array
    {
        if (! $order) {
            return [];
        }

        if (! $order->relationLoaded('items')) {
            $order->load('items');
        }
        if (! $order->relationLoaded('table')) {
            $order->load('table');
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];

        return [
            'id' => $order->id,
            'client_reference' => $order->client_reference,
            'customer_id' => $order->customer_id,
            'dining_table_id' => $order->dining_table_id,
            'table_session_id' => $order->table_session_id,
            'source' => $order->source,
            'table_name' => $order->table?->name ?? ($metadata['table_name'] ?? null),
            'session_local_id' => $metadata['cashier_session_local_id'] ?? null,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'pos_status' => $order->pos_status,
            'payment_status' => $order->payment_status,
            'notes' => $order->notes,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'tax_amount' => (float) $order->tax_amount,
            'total_amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
            'placed_at' => optional($order->placed_at)?->toIso8601String(),
            'items' => $order->items->values()->map(function ($item, int $index) use ($metadata) {
                $note = null;
                $stored = $metadata['kitchen_item_notes'][$index] ?? null;
                if (is_array($stored) && filled($stored['notes'] ?? null)) {
                    $note = (string) $stored['notes'];
                }

                return [
                    'id' => $item->id,
                    'pos_menu_item_id' => $item->pos_menu_item_id,
                    'product_name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'total_amount' => (float) $item->total_amount,
                    'notes' => $note,
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        return [
            'id' => $customer->id,
            'client_reference' => $customer->client_reference,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'notes' => $customer->notes,
            'updated_at' => optional($customer->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stockPayload(?InventoryMovement $movement): array
    {
        if (! $movement) {
            return [];
        }

        return [
            'id' => $movement->id,
            'product_id' => $movement->product_id,
            'product_variant_id' => $movement->product_variant_id,
            'type' => $movement->type,
            'quantity' => (int) $movement->quantity,
            'before_quantity' => (int) $movement->before_quantity,
            'after_quantity' => (int) $movement->after_quantity,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'notes' => $movement->notes,
        ];
    }
}
