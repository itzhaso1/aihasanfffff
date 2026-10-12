<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\ChatCatalogItem;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'chat_catalog_item_id',
    'minimum_selling_price',
    'maximum_selling_price',
])]
class WorkerEngagementProduct extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'minimum_selling_price' => 'decimal:2',
            'maximum_selling_price' => 'decimal:2',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }
}
