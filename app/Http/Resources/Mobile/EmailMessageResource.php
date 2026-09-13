<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @mixin \App\Models\EmailMessage */
class EmailMessageResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'email_account_id' => $this->email_account_id,
            'sender' => $this->sender,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'type' => $this->type,
            'delivery_status' => $this->delivery_status,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'email' => $this->account->email,
            ] : null),
        ];

        if (Schema::hasColumn('email_messages', 'read_at')) {
            $data['read_at'] = optional($this->read_at)?->toIso8601String();
            $data['is_read'] = $this->read_at !== null;
        }

        if (Schema::hasColumn('email_messages', 'starred_at')) {
            $data['starred_at'] = optional($this->starred_at)?->toIso8601String();
            $data['is_starred'] = $this->starred_at !== null;
        }

        if (Schema::hasColumn('email_messages', 'folder')) {
            $data['folder'] = $this->folder;
        }

        if ($this->detailed) {
            $data['body'] = $this->body;
            $data['in_reply_to'] = $this->in_reply_to;
            $data['thread_key'] = $this->thread_key;
            $data['attachments'] = $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->file_size,
            ]));
        } else {
            $data['preview'] = Str::limit(strip_tags((string) $this->body), 160);
        }

        return $data;
    }
}
