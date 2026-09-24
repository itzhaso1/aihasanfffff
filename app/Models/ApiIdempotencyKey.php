<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiIdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'key',
        'route',
        'request_hash',
        'status_code',
        'response_body',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
            'status_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function queryForWorkspace(int $workspaceId): Builder
    {
        return static::query()->where('workspace_id', $workspaceId);
    }
}
