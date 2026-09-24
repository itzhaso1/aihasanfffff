<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Device tokens are workspace-bound. Queries must always include workspace_id
 * (or run inside WorkspaceContext). This model is intentionally not globally
 * scoped so logout/revoke can target a user's tokens with an explicit workspace filter.
 */
class DevicePushToken extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'personal_access_token_id',
        'provider',
        'token',
        'platform',
        'device_name',
        'last_seen_at',
        'revoked_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public static function queryForWorkspace(int $workspaceId): Builder
    {
        return static::query()->where('workspace_id', $workspaceId);
    }
}
