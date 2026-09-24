<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ChatCatalogCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'parent_id',
    'name',
    'slug',
    'description',
    'image_path',
    'status',
    'is_visible',
    'is_featured',
    'is_active',
    'sort_order',
    'seo',
    'localization',
    'aliases',
])]
class ChatCatalogCategory extends WorkspaceScopedModel
{
    /** @use HasFactory<ChatCatalogCategoryFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'seo' => 'array',
            'localization' => 'array',
            'aliases' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function searchTerms(): HasMany
    {
        return $this->hasMany(ChatCatalogCategorySearchTerm::class, 'chat_catalog_category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChatCatalogItem::class, 'chat_catalog_category_id');
    }
}
