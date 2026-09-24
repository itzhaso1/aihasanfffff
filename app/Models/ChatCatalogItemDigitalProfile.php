<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chat_catalog_item_id',
    'chat_catalog_item_variant_id',
    'unit_type',
])]
class ChatCatalogItemDigitalProfile extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'unit_type' => ChatCatalogDigitalUnitType::class,
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
}
