<?php

namespace App\Http\Resources\Mobile;

use App\Models\Message;
use App\Services\Workforce\WorkforceInboxAttribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $clientId = $metadata['client_id'] ?? $metadata['idempotency_key'] ?? null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'content' => $this->content,
            'ai_generated' => (bool) $this->ai_generated,
            'participant_type' => $this->resource instanceof Message
                ? WorkforceInboxAttribution::typeForMessage($this->resource)?->value
                : null,
            'worker_engagement_id' => $this->worker_engagement_id,
            'client_id' => $clientId,
            'idempotency_key' => $clientId,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ] : null),
            'attachments' => MessageAttachmentResource::collection(
                $this->relationLoaded('attachments') ? $this->attachments : [],
            ),
        ];
    }
}
