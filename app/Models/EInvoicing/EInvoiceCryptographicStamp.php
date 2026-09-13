<?php

namespace App\Models\EInvoicing;

use App\EInvoicing\Security\StampStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable local signing artifact for one electronic document.
 * Does not store private keys, CSID secrets, or Tag 9 CA material.
 */
class EInvoiceCryptographicStamp extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'e_invoice_cryptographic_stamps';

    protected $guarded = [];

    protected $casts = [
        'finalized_at' => 'datetime',
        'stamp_status' => StampStatus::class,
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (): void {
            throw new RuntimeException('Electronic invoice cryptographic stamps are immutable.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Electronic invoice cryptographic stamps cannot be deleted.');
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

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(EInvoiceCertificate::class, 'e_invoice_certificate_id');
    }
}
