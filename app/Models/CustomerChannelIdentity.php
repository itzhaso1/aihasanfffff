<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerChannelIdentity extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'customer_id',
        'channel',
        'external_user_id',
        'username',
        'display_name',
        'avatar_storage_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
