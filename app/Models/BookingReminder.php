<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'booking_id',
    'reminder_type',
    'channel',
    'scheduled_for',
    'sent_at',
    'status',
    'message_id',
    'error_message',
])]
class BookingReminder extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const TYPE_CONFIRMATION = 'confirmation';

    public const TYPE_UPDATED = 'updated';

    public const TYPE_RESCHEDULED = 'rescheduled';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_UPCOMING = 'upcoming_reminder';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'message_id' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
