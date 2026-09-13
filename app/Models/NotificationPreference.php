<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'messages',
        'email',
        'marketing',
    ];

    protected function casts(): array
    {
        return [
            'messages' => 'boolean',
            'email' => 'boolean',
            'marketing' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
