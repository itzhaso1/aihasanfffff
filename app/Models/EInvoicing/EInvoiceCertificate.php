<?php

namespace App\Models\EInvoicing;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Immutable public certificate metadata for one EGS unit.
 * Private keys are never stored on this table.
 */
class EInvoiceCertificate extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_IMPORTED = 'imported';

    protected $table = 'e_invoice_certificates';

    protected $guarded = [];

    protected $casts = [
        'not_before' => 'datetime',
        'not_after' => 'datetime',
        'test_fixture' => 'boolean',
        'key_length_bits' => 'integer',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (): void {
            throw new RuntimeException('Electronic invoice certificates are immutable.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Electronic invoice certificates cannot be deleted.');
        });
    }

    public function egsUnit(): BelongsTo
    {
        return $this->belongsTo(EgsUnit::class, 'egs_unit_id');
    }

    public function stamps(): HasMany
    {
        return $this->hasMany(EInvoiceCryptographicStamp::class, 'e_invoice_certificate_id');
    }
}
