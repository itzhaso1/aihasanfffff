<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Meta\MetaTokenVault;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'business_open_id',
    'display_name',
    'status',
    'scopes',
    'metadata',
    'token_expires_at',
    'connected_by_user_id',
])]
class WorkspaceTikTokConnection extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected $table = 'workspace_tiktok_connections';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_ERROR = 'error';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token_ciphertext',
        'refresh_token_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'metadata' => 'array',
            'token_expires_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function storeTokens(?string $accessToken, ?string $refreshToken = null): void
    {
        $vault = app(MetaTokenVault::class);
        $this->access_token_ciphertext = $vault->encrypt($accessToken);
        if ($refreshToken !== null) {
            $this->refresh_token_ciphertext = $vault->encrypt($refreshToken);
        }
    }

    public function accessToken(): ?string
    {
        return app(MetaTokenVault::class)->decrypt($this->access_token_ciphertext);
    }

    public function refreshToken(): ?string
    {
        return app(MetaTokenVault::class)->decrypt($this->refresh_token_ciphertext);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['access_token_ciphertext'], $array['refresh_token_ciphertext']);

        return $array;
    }
}
