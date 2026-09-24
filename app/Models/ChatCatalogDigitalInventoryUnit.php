<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitStatus;
use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'chat_catalog_digital_inventory_pool_id',
    'unit_type',
    'status',
    'encrypted_secret',
    'secret_fingerprint',
    'reservation_key',
    'reserved_until',
    'expires_at',
    'metadata',
])]
class ChatCatalogDigitalInventoryUnit extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $hidden = [
        'encrypted_secret',
        'secret_fingerprint',
        'reservation_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'unit_type' => ChatCatalogDigitalUnitType::class,
            'status' => ChatCatalogDigitalUnitStatus::class,
            'encrypted_secret' => 'encrypted',
            'reserved_until' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogDigitalInventoryPool::class, 'chat_catalog_digital_inventory_pool_id');
    }
}
