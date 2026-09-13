<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\PosCashierInvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'pos_cashier_invoice_id',
    'pos_menu_item_id',
    'item_name',
    'item_type',
    'size_label',
    'quantity',
    'unit_code',
    'unit_price',
    'discount_amount',
    'taxable_amount',
    'tax_rate',
    'tax_amount',
    'total_amount',
])]
class PosCashierInvoiceItem extends WorkspaceScopedModel
{
    /** @use HasFactory<PosCashierInvoiceItemFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PosCashierInvoice::class, 'pos_cashier_invoice_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(PosMenuItem::class, 'pos_menu_item_id');
    }
}
