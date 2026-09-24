<?php

namespace App\Models\EInvoicing;

use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Enums\EInvoicing\PrivateKeyReferenceStatus;
use App\Enums\EInvoicing\PrivateKeyStoreDriver;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opaque handle to private-key material in an external store (KMS/HSM/dev memory).
 * Never stores PEM, secrets, or passphrases.
 */
class PrivateKeyReference extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'egs_private_key_references';

    protected $guarded = [];

    protected $hidden = [
        'store_key',
    ];

    protected function casts(): array
    {
        return [
            'environment' => EInvoicingEnvironment::class,
            'store_driver' => PrivateKeyStoreDriver::class,
            'status' => PrivateKeyReferenceStatus::class,
            'revoked_at' => 'datetime',
            'destroyed_at' => 'datetime',
        ];
    }

    public function egsUnit(): BelongsTo
    {
        return $this->belongsTo(EgsUnit::class, 'egs_unit_id');
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }
}
