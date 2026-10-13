<?php

namespace App\Http\Resources\Mobile;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Services\Workforce\WorkforceInboxAttribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
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
            'customer_priority' => $this->customer_priority ?? 'normal',
            'payment_status' => $this->payment_status ?? 'unpaid',
            'review_status' => $this->review_status ?? 'no_review_needed',
            'classification_source' => $this->classification_source ?? 'system',
            'classification_reason' => $this->classification_reason,
            'external_id' => $this->external_id,
            'ai_enabled' => (bool) $this->ai_enabled,
            'ai_replying' => $this->resource instanceof Conversation
                ? $this->resource->isAiReplying()
                : false,
            'handoff_status' => $this->handoff_status instanceof ConversationHandoffStatus
                ? $this->handoff_status->value
                : ($this->handoff_status ?? 'none'),
            'handoff_reason' => $this->handoff_reason instanceof ConversationHandoffReason
                ? $this->handoff_reason->value
                : $this->handoff_reason,
            'handoff_requested_at' => optional($this->handoff_requested_at)?->toIso8601String(),
            'handoff_assigned_at' => optional($this->handoff_assigned_at)?->toIso8601String(),
            'handoff_resolved_at' => optional($this->handoff_resolved_at)?->toIso8601String(),
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_worker_engagement_id' => $this->assigned_worker_engagement_id,
            'assigned_participant_type' => $this->resource instanceof Conversation
                ? WorkforceInboxAttribution::typeForConversation($this->resource)?->value
                : null,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'handoff_summary' => data_get($this->metadata, 'handoff.structured'),
            'catalog_context' => data_get($this->metadata, 'catalog') ? [
                'catalog_id' => data_get($this->metadata, 'catalog.catalog_id'),
                'catalog_item_id' => data_get($this->metadata, 'catalog.catalog_item_id'),
                'variant_id' => data_get($this->metadata, 'catalog.variant_id'),
                'source' => data_get($this->metadata, 'catalog.source'),
                'product_name' => data_get($this->metadata, 'catalog.attached_product.name'),
            ] : null,
            'last_message_at' => optional($this->last_message_at)?->toIso8601String(),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'unread_count' => $this->when(isset($this->unread_count), fn () => (int) $this->unread_count),
            'last_message' => $lastMessage,
            'muted' => $this->when(isset($this->muted), fn () => (bool) $this->muted),
            'archived' => $this->when(isset($this->archived), fn () => (bool) $this->archived),
            'can_reply' => $this->when(isset($this->can_reply), fn () => (bool) $this->can_reply),
        ];
    }
}
