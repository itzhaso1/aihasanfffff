<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Conversation */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastMessage = $this->when(
            isset($this->last_message) || $this->relationLoaded('messages'),
            fn () => new MessageResource(
                $this->last_message ?? $this->messages->first(),
            ),
        );

        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'status' => $this->status,
            'external_id' => $this->external_id,
            'ai_enabled' => (bool) $this->ai_enabled,
            'last_message_at' => optional($this->last_message_at)?->toIso8601String(),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'unread_count' => $this->when(isset($this->unread_count), fn () => (int) $this->unread_count),
            'last_message' => $lastMessage,
            'muted' => $this->when(isset($this->muted), fn () => (bool) $this->muted),
            'archived' => $this->when(isset($this->archived), fn () => (bool) $this->archived),
        ];
    }
}
