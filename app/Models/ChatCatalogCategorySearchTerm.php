<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chat_catalog_category_id',
    'term',
    'language',
])]
class ChatCatalogCategorySearchTerm extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogCategory::class, 'chat_catalog_category_id');
    }
}
