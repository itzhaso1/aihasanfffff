<?php

namespace App\Services\Conversation;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Message;

final class ConversationHandoffSummaryBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Conversation $conversation): array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $catalog = data_get($metadata, 'catalog');
        $productName = is_array($catalog) ? (string) data_get($catalog, 'attached_product.name', '') : '';
        $variantName = is_array($catalog) ? (string) data_get($catalog, 'attached_product.selected_variant.name', '') : '';

        $latestCustomer = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('message_type', 'text')
            ->orderByDesc('id')
            ->value('content');

        $reason = $conversation->handoff_reason;
        if ($reason instanceof ConversationHandoffReason) {
            $reason = $reason->value;
        }

        $status = $conversation->handoff_status;
        if ($status instanceof ConversationHandoffStatus) {
            $status = $status->value;
        }

        return [
            'reason' => $reason,
            'handoff_status' => $status,
            'latest_customer_message' => is_string($latestCustomer) ? mb_substr($latestCustomer, 0, 500) : null,
            'product' => $productName !== '' ? $productName : null,
            'variant' => $variantName !== '' ? $variantName : null,
            'catalog' => is_array($catalog) ? array_filter([
                'catalog_id' => data_get($catalog, 'catalog_id'),
                'catalog_item_id' => data_get($catalog, 'catalog_item_id'),
                'variant_id' => data_get($catalog, 'variant_id'),
                'source' => data_get($catalog, 'source'),
            ], fn ($v) => $v !== null && $v !== '') : null,
            'ai_state' => $conversation->ai_enabled ? 'enabled' : 'disabled',
            'requested_at' => optional($conversation->handoff_requested_at)?->toIso8601String(),
            'assigned_at' => optional($conversation->handoff_assigned_at)?->toIso8601String(),
            'resolved_at' => optional($conversation->handoff_resolved_at)?->toIso8601String(),
            'assigned_user_id' => $conversation->assigned_user_id,
            'assigned_worker_engagement_id' => $conversation->assigned_worker_engagement_id,
        ];
    }
}
