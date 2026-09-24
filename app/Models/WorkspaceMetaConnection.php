<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Meta\MetaTokenVault;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'kind',
    'external_id',
    'parent_connection_id',
    'display_name',
    'status',
    'token_expires_at',
    'scopes',
    'metadata',
    'connected_by_user_id',
])]
class WorkspaceMetaConnection extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'metadata' => 'array',
        ];
    }

    public function parentConnection(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_connection_id');
    }

    public function childConnections(): HasMany
    {
        return $this->hasMany(self::class, 'parent_connection_id');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function storeAccessToken(?string $accessToken): void
    {
        $this->access_token_ciphertext = app(MetaTokenVault::class)->encrypt($accessToken);
    }

    public function accessToken(): ?string
    {
        return app(MetaTokenVault::class)->decrypt($this->access_token_ciphertext);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['access_token_ciphertext']);

        return $array;
    }
}
