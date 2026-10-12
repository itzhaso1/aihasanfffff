<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Conversation;
use App\Services\Workforce\WorkforceInboxAttribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
class WorkerConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'status' => $this->status,
            'assigned_worker_engagement_id' => $this->assigned_worker_engagement_id,
            'assigned_participant_type' => $this->resource instanceof Conversation
                ? WorkforceInboxAttribution::typeForConversation($this->resource)?->value
                : null,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ] : null),
            'last_message_at' => optional($this->last_message_at)?->toIso8601String(),
        ];
    }
}
