<?php

namespace App\Models\EInvoicing;

use App\Enums\EInvoicing\EgsOnboardingStatus;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EGS onboarding workflow state (CSR and later ZATCA steps). Never stores private keys or secrets.
 */
class EgsOnboarding extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'egs_onboarding';

    protected $guarded = [];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'environment' => EInvoicingEnvironment::class,
            'status' => EgsOnboardingStatus::class,
            'csr_generated_at' => 'datetime',
        ];
    }

    public function egsUnit(): BelongsTo
    {
        return $this->belongsTo(EgsUnit::class, 'egs_unit_id');
    }

    public function privateKeyReference(): BelongsTo
    {
        return $this->belongsTo(PrivateKeyReference::class, 'private_key_reference_id');
    }
}
