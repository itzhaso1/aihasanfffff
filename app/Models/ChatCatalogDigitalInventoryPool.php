<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'chat_catalog_item_id',
    'chat_catalog_item_variant_id',
    'name',
    'low_stock_threshold',
    'reservation_timeout_seconds',
])]
class ChatCatalogDigitalInventoryPool extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'low_stock_threshold' => 'integer',
            'reservation_timeout_seconds' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItemVariant::class, 'chat_catalog_item_variant_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ChatCatalogDigitalInventoryUnit::class, 'chat_catalog_digital_inventory_pool_id');
    }

    public function availableUnits(): HasMany
    {
        return $this->units()
            ->where('status', ChatCatalogDigitalUnitStatus::Available->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
