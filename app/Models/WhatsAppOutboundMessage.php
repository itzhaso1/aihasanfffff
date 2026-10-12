<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'whats_app_phone_number_id',
    'conversation_id',
    'message_id',
    'to',
    'type',
    'body',
    'template_name',
    'provider_message_id',
    'status',
    'attempts',
    'last_error',
    'payload',
    'provider_response',
    'sent_at',
    'delivered_at',
    'failed_at',
])]
class WhatsAppOutboundMessage extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'whatsapp_outbound_messages';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELIVERED = 'delivered';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'payload' => 'array',
            'provider_response' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsAppPhoneNumber::class, 'whats_app_phone_number_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
