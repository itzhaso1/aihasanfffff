<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'provider',
    'provider_user_id',
    'provider_email',
    'provider_data',
    'access_token',
    'refresh_token',
    'token_expires_at',
])]
class AuthIdentity extends Model
{
    protected function casts(): array
    {
        return [
            'provider_data' => 'array',
            'token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
