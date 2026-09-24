<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Support\Storage\PrivateDocumentDisk;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Fillable([
    'workspace_id',
    'invoice_id',
    'file_path',
    'file_name',
    'file_type',
    'file_size',
    'uploaded_by',
])]
class FinanceInvoiceAttachment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function downloadResponse(): StreamedResponse
    {
        return PrivateDocumentDisk::download(
            (string) $this->file_path,
            $this->file_name ?: ('invoice-attachment-'.$this->id),
        );
    }

    public function deleteFile(): void
    {
        PrivateDocumentDisk::delete((string) $this->file_path);
    }

    protected static function booted(): void
    {
        parent::booted();

        $guard = static function (FinanceInvoiceAttachment $attachment): void {
            $invoiceId = (int) ($attachment->invoice_id ?: $attachment->getOriginal('invoice_id'));
            if ($invoiceId <= 0) {
                return;
            }

            $invoice = FinanceInvoice::withoutGlobalScopes()->find($invoiceId);
            if ($invoice?->isFinanciallyLocked()) {
                throw new RuntimeException('لا يمكن تعديل مرفقات فاتورة معتمدة أو ملغاة.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
