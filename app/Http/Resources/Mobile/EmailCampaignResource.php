<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmailCampaign */
class EmailCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email_account_id' => $this->email_account_id,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status,
            'recipient_count' => (int) $this->recipient_count,
            'sent_count' => (int) $this->sent_count,
            'failed_count' => (int) $this->failed_count,
            'error_message' => $this->error_message,
            'queued_at' => optional($this->queued_at)?->toIso8601String(),
            'started_at' => optional($this->started_at)?->toIso8601String(),
            'completed_at' => optional($this->completed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'email' => $this->account->email,
            ] : null),
            'pending_count' => $this->when(isset($this->pending_count), (int) $this->pending_count),
        ];
    }
}
