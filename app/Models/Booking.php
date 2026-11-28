<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'booking_reference',
    'customer_id',
    'conversation_id',
    'service_id',
    'branch_id',
    'provider_id',
    'delivery_location_id',
    'service_name',
    'customer_name',
    'customer_phone',
    'booking_date',
    'start_time',
    'end_time',
    'starts_at',
    'ends_at',
    'duration_minutes',
    'party_size',
    'status',
    'source',
    'channel',
    'customer_notes',
    'internal_notes',
    'custom_fields',
    'source_message_id',
    'idempotency_key',
    'rescheduled_from_booking_id',
    'reschedule_count',
    'previous_starts_at',
    'confirmed_at',
    'rescheduled_at',
    'cancelled_at',
    'completed_at',
    'no_show_at',
    'cancellation_reason',
    'created_by_user_id',
    'updated_by_user_id',
    'metadata',
])]
class Booking extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'conversation_id' => 'integer',
            'service_id' => 'integer',
            'branch_id' => 'integer',
            'provider_id' => 'integer',
            'delivery_location_id' => 'integer',
            'booking_date' => 'date:Y-m-d',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'party_size' => 'integer',
            'custom_fields' => 'array',
            'source_message_id' => 'integer',
            'rescheduled_from_booking_id' => 'integer',
            'reschedule_count' => 'integer',
            'previous_starts_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'no_show_at' => 'datetime',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'service_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BookingBranch::class, 'branch_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BookingProvider::class, 'provider_id');
    }

    public function deliveryLocation(): BelongsTo
    {
        return $this->belongsTo(CustomerLocation::class, 'delivery_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BookingRevision::class, 'booking_id')->orderByDesc('id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class, 'booking_id')->orderBy('scheduled_for');
    }

    public function statusEnum(): BookingStatus
    {
        return BookingStatus::tryFrom((string) $this->status) ?? BookingStatus::Pending;
    }

    public function statusLabelAr(): string
    {
        return $this->statusEnum()->labelAr();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(string $timezone = 'Asia/Riyadh'): array
    {
        $localStart = $this->starts_at?->copy()->setTimezone($timezone);

        return [
            'id' => (int) $this->id,
            'booking_reference' => $this->booking_reference,
            'status' => $this->status,
            'status_label' => $this->statusLabelAr(),
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'service_id' => $this->service_id ? (int) $this->service_id : null,
            'service_name' => $this->service_name,
            'price' => $this->relationLoaded('service') ? $this->service?->formattedPrice() : null,
            'currency' => $this->relationLoaded('service') ? ($this->service?->currency ?: 'SAR') : null,
            'branch_id' => $this->branch_id ? (int) $this->branch_id : null,
            'branch_name' => $this->relationLoaded('branch') ? $this->branch?->name : null,
            'provider_id' => $this->provider_id ? (int) $this->provider_id : null,
            'provider_name' => $this->relationLoaded('provider') ? $this->provider?->name : null,
            'booking_date' => $this->booking_date?->format('Y-m-d') ?? $localStart?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $this->duration_minutes,
            'party_size' => $this->party_size !== null ? (int) $this->party_size : null,
            'source' => $this->source,
            'channel' => $this->channel,
            'customer_notes' => $this->customer_notes,
            'custom_fields' => $this->custom_fields ?? [],
        ];
    }
}
