<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Support\Storage\PrivateDocumentDisk;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Fillable([
    'workspace_id',
    'supplier_id',
    'category_id',
    'treasury_account_id',
    'expense_number',
    'expense_date',
    'description',
    'amount',
    'tax_rate',
    'tax_amount',
    'total',
    'currency',
    'payment_method',
    'status',
    'is_recurring',
    'recurring_frequency',
    'next_due_date',
    'attachment_path',
    'metadata',
    'created_by',
])]
class FinanceExpense extends WorkspaceScopedModel
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'is_recurring' => 'boolean',
            'next_due_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FinanceSupplier::class, 'supplier_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceExpenseCategory::class, 'category_id');
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceTreasuryAccount::class, 'treasury_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachmentDownloadResponse(?string $filename = null): StreamedResponse
    {
        abort_unless(filled($this->attachment_path), 404);

        return PrivateDocumentDisk::download(
            (string) $this->attachment_path,
            $filename ?? basename((string) $this->attachment_path),
        );
    }

    public function deleteAttachmentFile(): void
    {
        if (filled($this->attachment_path)) {
            PrivateDocumentDisk::delete((string) $this->attachment_path);
        }
    }
}
