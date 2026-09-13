<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Product;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'quote_id',
    'product_id',
    'product_name',
    'description',
    'unit',
    'unit_code',
    'quantity',
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
class FinanceQuoteItem extends WorkspaceScopedModel
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(FinanceQuote::class, 'quote_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        parent::booted();

        $guard = static function (FinanceQuoteItem $item): void {
            $quoteId = (int) ($item->quote_id ?: $item->getOriginal('quote_id'));
            if ($quoteId <= 0) {
                return;
            }

            $quote = FinanceQuote::withoutGlobalScopes()->find($quoteId);
            if ($quote?->isLocked()) {
                throw new RuntimeException('لا يمكن تعديل بنود عرض سعر صادر أو ملغى.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
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
