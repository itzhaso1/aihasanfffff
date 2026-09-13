<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'credit_note_id',
    'product_name',
    'description',
    'quantity',
    'unit_code',
    'unit_price',
    'discount',
    'tax_profile_type',
    'exemption_reason',
    'exemption_code',
    'tax_rate',
    'tax_amount',
    'taxable_amount',
    'total',
    'metadata',
])]
class FinanceCreditNoteItem extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(FinanceCreditNote::class, 'credit_note_id');
    }

    protected static function booted(): void
    {
        parent::booted();

        $guard = static function (FinanceCreditNoteItem $item): void {
            $noteId = (int) ($item->credit_note_id ?: $item->getOriginal('credit_note_id'));
            if ($noteId <= 0) {
                return;
            }

            $note = FinanceCreditNote::withoutGlobalScopes()->find($noteId);
            if ($note?->isFinanciallyLocked()) {
                throw new RuntimeException('لا يمكن تعديل بنود إشعار معتمد أو ملغى.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public static function hasTaxProfileColumn(): bool
    {
        return Schema::hasColumn('finance_credit_note_items', 'tax_profile_type');
    }

    public static function hasExemptionColumns(): bool
    {
        return Schema::hasColumn('finance_credit_note_items', 'exemption_reason')
            && Schema::hasColumn('finance_credit_note_items', 'exemption_code');
    }

    public static function hasUnitCodeColumn(): bool
    {
        return Schema::hasColumn('finance_credit_note_items', 'unit_code');
    }
}
