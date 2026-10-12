<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_engagement_id',
    'user_id',
    'party',
    'decision',
    'terms_version',
    'accepted_at',
    'ip_address',
    'user_agent',
])]
class WorkerEngagementAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
