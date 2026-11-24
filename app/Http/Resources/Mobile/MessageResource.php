<?php

namespace App\Http\Resources\Mobile;

use App\Models\Message;
use App\Services\Workforce\WorkforceInboxAttribution;
use App\Support\Tenancy\WorkspaceContext;
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
        if (! $this->resource) {
            return [];
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $clientId = $metadata['client_id'] ?? $metadata['idempotency_key'] ?? null;

        $customerName = null;
        if ($this->relationLoaded('customer')) {
            $customerName = $this->customer?->name;
        } elseif ($this->customer_id && $this->workspace_id) {
            try {
                $customerName = app(WorkspaceContext::class)->runForId((int) $this->workspace_id, fn () => $this->customer?->name);
            } catch (\Throwable) {
                $customerName = null;
            }
        }

        $senderName = null;
        if ($this->ai_generated) {
            $senderName = 'مشعل AI';
        } elseif ($this->worker_engagement_id !== null) {
            $profile = $this->relationLoaded('workerEngagement') ? $this->workerEngagement?->workerProfile : null;
            $senderName = $profile?->display_name ?? $profile?->user?->name ?? $this->user?->name ?? 'موظف مستقل';
        } elseif ($this->user_id !== null || $this->direction === 'outbound') {
            $senderName = $this->user?->name ?? 'الموظف';
        } elseif ($customerName) {
            $senderName = $customerName;
        } else {
            $senderName = 'العميل';
        }

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'customer_name' => $customerName,
            'sender_name' => $senderName,
            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'content' => $this->content,
            'ai_generated' => (bool) $this->ai_generated,
            'delivery_status' => $this->delivery_status,
            'delivered_at' => optional($this->delivered_at)?->toIso8601String(),
            'read_at' => optional($this->read_at)?->toIso8601String(),
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
