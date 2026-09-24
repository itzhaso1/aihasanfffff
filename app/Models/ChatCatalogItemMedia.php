<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chat_catalog_item_id',
    'kind',
    'disk',
    'path',
    'alt',
    'sort_order',
    'focal_metadata',
    'is_visible',
])]
class ChatCatalogItemMedia extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'focal_metadata' => 'array',
            'is_visible' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }
}
