<?php

namespace App\Models\Finance;

use App\Enums\Finance\DocumentDeliveryChannel;
use App\Enums\Finance\DocumentDeliveryStatus;
use App\Enums\Finance\FinanceDocumentType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\EmailLog;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'document_type',
    'document_id',
    'channel',
    'recipient',
    'recipient_phone',
    'subject',
    'status',
    'provider_message_id',
    'email_log_id',
    'sent_by',
    'attachment_disk',
    'attachment_path',
    'error',
    'meta',
    'sent_at',
])]
class FinanceDocumentDelivery extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const TYPE_QUOTE = 'quote';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_INVOICE_REMINDER = 'invoice_reminder';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(FinanceQuote::class, 'document_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'document_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FinanceReceipt::class, 'document_id');
    }

    public function scopeForQuote(Builder $query, int $quoteId): Builder
    {
        return $query
            ->where('document_type', FinanceDocumentType::Quote->value)
            ->where('document_id', $quoteId);
    }

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query
            ->whereIn('document_type', [
                FinanceDocumentType::Invoice->value,
                FinanceDocumentType::InvoiceReminder->value,
            ])
            ->where('document_id', $invoiceId);
    }

    public function scopeForReceipt(Builder $query, int $receiptId): Builder
    {
        return $query
            ->where('document_type', FinanceDocumentType::Receipt->value)
            ->where('document_id', $receiptId);
    }

    public function deliveryStatus(): DocumentDeliveryStatus
    {
        return DocumentDeliveryStatus::tryFrom((string) $this->status)
            ?? DocumentDeliveryStatus::Sending;
    }

    public function deliveryChannel(): DocumentDeliveryChannel
    {
        return DocumentDeliveryChannel::tryFrom((string) $this->channel)
            ?? DocumentDeliveryChannel::Email;
    }

    public function isSent(): bool
    {
        return $this->deliveryStatus() === DocumentDeliveryStatus::Sent;
    }

    public function isFailed(): bool
    {
        return $this->deliveryStatus() === DocumentDeliveryStatus::Failed;
    }
}
