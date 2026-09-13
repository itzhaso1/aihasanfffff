<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'quote_id',
    'file_path',
    'file_name',
    'file_type',
    'file_size',
    'uploaded_by',
])]
class FinanceQuoteAttachment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function quote(): BelongsTo
    {
        return $this->belongsTo(FinanceQuote::class, 'quote_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function deleteFile(): void
    {
        if (is_string($this->file_path) && $this->file_path !== '') {
            Storage::disk('public')->delete($this->file_path);
        }
    }

    protected static function booted(): void
    {
        parent::booted();

        $guard = static function (FinanceQuoteAttachment $attachment): void {
            $quoteId = (int) ($attachment->quote_id ?: $attachment->getOriginal('quote_id'));
            if ($quoteId <= 0) {
                return;
            }

            $quote = FinanceQuote::withoutGlobalScopes()->find($quoteId);
            if ($quote?->isCancelled()) {
                throw new RuntimeException('لا يمكن تعديل مرفقات عرض سعر ملغى.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
