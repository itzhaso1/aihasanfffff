<?php

namespace App\Models\EInvoicing;

use App\Enums\EInvoicing\SecurityStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Local security-chain artifacts for one electronic document.
 *
 * Does not store private keys, CSID, FATOORA/Clearance/Reporting responses,
 * or QR payloads.
 */
class EInvoiceSecurityRecord extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'e_invoice_security_records';

    protected $guarded = [];

    protected $casts = [
        'icv' => 'integer',
        'finalized_at' => 'datetime',
        'security_status' => SecurityStatus::class,
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (): void {
            throw new RuntimeException('Electronic invoice security records are immutable.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Electronic invoice security records cannot be deleted.');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EInvoiceDocumentRecord::class, 'e_invoice_document_id');
    }

    public function egsUnit(): BelongsTo
    {
        return $this->belongsTo(EgsUnit::class, 'egs_unit_id');
    }
}
