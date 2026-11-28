<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'customer_id',
    'conversation_id',
    'order_id',
    'latitude',
    'longitude',
    'accuracy_meters',
    'source',
    'platform',
    'verification_status',
    'country_code',
    'country',
    'city',
    'district',
    'street',
    'postal_code',
    'place_name',
    'formatted_address',
    'customer_notes',
    'geocoding_provider',
    'geocoding_status',
    'external_message_id',
    'raw_payload',
    'confirmed_at',
    'superseded_by_id',
    'created_by_user_id',
])]
class CustomerLocation extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_NEEDS_ADJUSTMENT = 'needs_adjustment';

    public const STATUS_SUPERSEDED = 'superseded';

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'integer',
            'raw_payload' => 'array',
            'confirmed_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(LocationCaptureRevision::class, 'customer_location_id');
    }

    public function googleMapsUrl(): string
    {
        return sprintf(
            'https://www.google.com/maps?q=%s,%s',
            number_format((float) $this->latitude, 7, '.', ''),
            number_format((float) $this->longitude, 7, '.', ''),
        );
    }

    public function verificationLabel(): string
    {
        return match ($this->verification_status) {
            self::STATUS_CONFIRMED => 'موقع مؤكد',
            self::STATUS_NEEDS_ADJUSTMENT => 'يحتاج مراجعة / تعديل',
            self::STATUS_SUPERSEDED => 'تم استبداله',
            default => 'بانتظار التأكيد',
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'gps' => 'GPS مباشر',
            'gps_adjusted' => 'GPS + تعديل يدوي',
            'manual_pin' => 'تحديد يدوي على الخريطة',
            'search_pin' => 'بحث على الخريطة',
            'whatsapp_location' => 'مشاركة موقع واتساب',
            'telegram_location' => 'مشاركة موقع تيليجرام',
            'telegram_venue' => 'موقع مكان تيليجرام',
            'facebook_location' => 'موقع فيسبوك مسنجر',
            'instagram_location' => 'موقع إنستغرام',
            'agent_manual' => 'تحديث موظف',
            default => (string) $this->source,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => (int) $this->id,
            'latitude' => round((float) $this->latitude, 7),
            'longitude' => round((float) $this->longitude, 7),
            'accuracy_meters' => $this->accuracy_meters,
            'source' => $this->source,
            'source_label' => $this->sourceLabel(),
            'platform' => $this->platform,
            'verification_status' => $this->verification_status,
            'verification_label' => $this->verificationLabel(),
            'formatted_address' => $this->formatted_address,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'place_name' => $this->place_name,
            'customer_notes' => $this->customer_notes,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'google_maps_url' => $this->googleMapsUrl(),
        ];
    }
}
