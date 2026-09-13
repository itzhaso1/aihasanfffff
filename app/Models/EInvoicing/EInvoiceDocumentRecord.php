<?php

namespace App\Models\EInvoicing;

use App\EInvoicing\ComplianceStateMachine;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * Persisted electronic-invoice identity and compliance lifecycle.
 *
 * Document amounts live in {@see IssuedDocumentSnapshot}; this row does not
 * recalculate tax. Hash/ICV/PIH live on {@see EInvoiceSecurityRecord}.
 */
class EInvoiceDocumentRecord extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'e_invoice_documents';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'issue_date' => 'date',
        'issued_at' => 'datetime',
        'document_kind' => ElectronicDocumentKind::class,
        'type_code' => InvoiceTypeCode::class,
        'transaction_code' => InvoiceTransactionCode::class,
        'compliance_status' => ComplianceStatus::class,
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $record): void {
            $locked = [
                'workspace_id',
                'issued_document_snapshot_id',
                'source_type',
                'source_id',
                'document_kind',
                'type_code',
                'transaction_code',
                'document_number',
                'issue_date',
                'issued_at',
                'currency',
                'payload',
            ];

            foreach ($locked as $field) {
                if (! $record->isDirty($field)) {
                    continue;
                }

                $original = $record->getOriginal($field);
                $current = $record->getAttribute($field);
                if (self::lockedValuesMatch($original, $current)) {
                    continue;
                }

                throw new RuntimeException(
                    'Electronic invoice documents are immutable except for compliance_status.'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Electronic invoice documents cannot be deleted.');
        });
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(IssuedDocumentSnapshot::class, 'issued_document_snapshot_id');
    }

    public function securityRecord(): HasOne
    {
        return $this->hasOne(EInvoiceSecurityRecord::class, 'e_invoice_document_id');
    }

    public function transitionCompliance(ComplianceStatus $to): void
    {
        ComplianceStateMachine::assertCanTransition($this->compliance_status, $to);

        $this->forceFill([
            'compliance_status' => $to,
        ])->save();
    }

    private static function lockedValuesMatch(mixed $original, mixed $current): bool
    {
        if ($original === $current) {
            return true;
        }

        if ($original instanceof \BackedEnum && $current instanceof \BackedEnum) {
            return $original->value === $current->value;
        }

        if ($original instanceof \DateTimeInterface && $current instanceof \DateTimeInterface) {
            return $original->getTimestamp() === $current->getTimestamp();
        }

        if (is_array($original) || is_array($current)) {
            return $original === $current;
        }

        return (string) $original === (string) $current;
    }
}
