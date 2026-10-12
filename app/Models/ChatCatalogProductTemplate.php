<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogProductTemplateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'slug',
    'name',
    'classification_hint',
    'status',
    'schema_version',
    'is_system',
    'source_template_id',
])]
class ChatCatalogProductTemplate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ChatCatalogProductTemplateStatus::class,
            'schema_version' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_template_id');
    }

    public function clonedTemplates(): HasMany
    {
        return $this->hasMany(self::class, 'source_template_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ChatCatalogProductTemplateField::class, 'product_template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChatCatalogItem::class, 'product_template_id');
    }

    public function isAccessibleByWorkspace(int $workspaceId): bool
    {
        if ($this->is_system && $this->workspace_id === null) {
            return true;
        }

        return (int) $this->workspace_id === $workspaceId;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAccessibleByWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where(function (Builder $inner) use ($workspaceId): void {
            $inner->where(function (Builder $system): void {
                $system->where('is_system', true)->whereNull('workspace_id');
            })->orWhere('workspace_id', $workspaceId);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ChatCatalogProductTemplateStatus::Active);
    }
}
