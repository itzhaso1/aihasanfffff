<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chat_catalog_item_id',
    'term',
    'term_type',
    'language',
])]
class ChatCatalogItemSearchTerm extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }
}
