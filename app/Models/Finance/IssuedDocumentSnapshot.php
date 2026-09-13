<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\PosCashierInvoice;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'source_type',
    'source_id',
    'document_number',
    'issue_date',
    'issued_at',
    'currency',
    'payload',
])]
class IssuedDocumentSnapshot extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const SOURCE_FINANCE_INVOICE = 'finance_invoice';

    public const SOURCE_FINANCE_CREDIT_NOTE = 'finance_credit_note';

    public const SOURCE_FINANCE_DEBIT_NOTE = 'finance_debit_note';

    public const SOURCE_POS_CASHIER_INVOICE = 'pos_cashier_invoice';

    /**
     * Payload schema for newly captured snapshots.
     * Existing rows keep the version frozen in payload.metadata.schema_version.
     */
    public const SCHEMA_VERSION = 2;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $snapshot): void {
            throw new RuntimeException('Issued document snapshots are immutable and cannot be updated.');
        });

        static::deleting(function (self $snapshot): void {
            throw new RuntimeException('Issued document snapshots are immutable and cannot be deleted.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'source_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(FinanceCreditNote::class, 'source_id');
    }

    public function posCashierInvoice(): BelongsTo
    {
        return $this->belongsTo(PosCashierInvoice::class, 'source_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $section = $payload[$key] ?? [];

        return is_array($section) ? $section : [];
    }
}
