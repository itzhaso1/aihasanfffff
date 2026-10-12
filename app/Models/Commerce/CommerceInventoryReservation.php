<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceReservationStatus;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'order_id',
    'order_item_id',
    'chat_catalog_item_id',
    'chat_catalog_item_variant_id',
    'chat_catalog_digital_inventory_unit_id',
    'reservation_kind',
    'status',
    'reservation_key',
    'quantity',
    'expires_at',
    'metadata',
])]
class CommerceInventoryReservation extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_inventory_reservations';

    protected function casts(): array
    {
        return [
            'status' => CommerceReservationStatus::class,
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItemVariant::class, 'chat_catalog_item_variant_id');
    }

    public function digitalUnit(): BelongsTo
    {
        return $this->belongsTo(
            ChatCatalogDigitalInventoryUnit::class,
            'chat_catalog_digital_inventory_unit_id',
        );
    }
}
