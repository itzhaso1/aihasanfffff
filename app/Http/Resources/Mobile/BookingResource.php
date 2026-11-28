<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'booking_reference' => $this->booking_reference,
            'status' => $this->status,
            'status_label' => $this->statusLabelAr(),
            'customer_id' => $this->customer_id ? (int) $this->customer_id : null,
            'conversation_id' => $this->conversation_id ? (int) $this->conversation_id : null,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'service_id' => $this->service_id ? (int) $this->service_id : null,
            'service_name' => $this->service_name,
            'branch_id' => $this->branch_id ? (int) $this->branch_id : null,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'provider_id' => $this->provider_id ? (int) $this->provider_id : null,
            'provider_name' => $this->whenLoaded('provider', fn () => $this->provider?->name),
            'booking_date' => $this->booking_date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $this->duration_minutes,
            'party_size' => $this->party_size !== null ? (int) $this->party_size : null,
            'source' => $this->source,
            'channel' => $this->channel,
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->internal_notes,
            'custom_fields' => $this->custom_fields ?? [],
            'cancellation_reason' => $this->cancellation_reason,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'rescheduled_at' => $this->rescheduled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'no_show_at' => $this->no_show_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
