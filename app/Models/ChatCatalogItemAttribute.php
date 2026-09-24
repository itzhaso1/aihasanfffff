<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chat_catalog_item_id',
    'chat_catalog_item_variant_id',
    'attribute_key',
    'attribute_value',
    'value_type',
    'sort_order',
])]
class ChatCatalogItemAttribute extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
