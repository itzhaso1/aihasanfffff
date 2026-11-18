<?php

namespace App\Http\Resources\Mobile;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'status' => $this->status instanceof CustomerStatus ? $this->status->value : (string) ($this->status ?? 'needs_review'),
            'status_label' => $this->statusEnum()->label(),
            'email' => $this->email,
            'whatsapp' => $this->whatsapp,
            'city' => $this->city,
            'orders_count' => (int) $this->orders_count,
            'total_purchases' => (string) ($this->total_purchases ?? '0.00'),
            'last_order_at' => $this->last_order_at?->toISOString(),
            'last_conversation_at' => $this->last_conversation_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'notes' => $this->relationLoaded('notes') ? $this->notes->map(fn ($n) => [
                'id' => $n->id,
                'body' => $n->body,
                'created_at' => $n->created_at?->toISOString(),
                'user_name' => $n->user?->name,
            ]) : [],
        ];
    }
}
