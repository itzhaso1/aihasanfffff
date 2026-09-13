<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Product;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'invoice_id',
    'product_id',
    'product_name',
    'description',
    'quantity',
    'unit_code',
    'unit',
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
class FinanceInvoiceItem extends WorkspaceScopedModel
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        parent::booted();

        $guard = static function (FinanceInvoiceItem $item): void {
            $invoiceId = (int) ($item->invoice_id ?: $item->getOriginal('invoice_id'));
            if ($invoiceId <= 0) {
                return;
            }

            $invoice = FinanceInvoice::withoutGlobalScopes()->find($invoiceId);
            if ($invoice?->isFinanciallyLocked()) {
                throw new RuntimeException('لا يمكن تعديل بنود فاتورة معتمدة أو ملغاة.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public static function hasTaxProfileColumn(): bool
    {
        return Schema::hasColumn('finance_invoice_items', 'tax_profile_type');
    }

    public static function hasExemptionColumns(): bool
    {
        return Schema::hasColumn('finance_invoice_items', 'exemption_reason')
            && Schema::hasColumn('finance_invoice_items', 'exemption_code');
    }

    public static function hasUnitColumn(): bool
    {
        return Schema::hasColumn('finance_invoice_items', 'unit');
    }

    public static function hasUnitCodeColumn(): bool
    {
        return Schema::hasColumn('finance_invoice_items', 'unit_code');
    }

    public function lineTitle(): string
    {
        $name = trim((string) $this->product_name);
        if ($name !== '') {
            return $name;
        }

        return trim((string) $this->description);
    }

    public function displayUnit(): string
    {
        $unit = trim((string) ($this->unit ?? ''));
        if ($unit !== '') {
            return $unit;
        }

        return trim((string) ($this->unit_code ?? ''));
    }
}
