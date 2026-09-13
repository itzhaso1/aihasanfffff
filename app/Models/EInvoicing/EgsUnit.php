<?php

namespace App\Models\EInvoicing;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\Uuid;

/**
 * Electronic invoicing solution (EGS) unit.
 *
 * Workspace is not an EGS unit. Each workspace has at least one EGS unit that
 * owns the ICV/PIH security sequence. Additional units may be created; this
 * phase does not invent branches or devices.
 */
class EgsUnit extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_ACTIVE = 'active';

    protected $table = 'egs_units';

    protected $guarded = [];

    protected $casts = [
        'next_icv' => 'integer',
    ];

    public static function defaultUuidForWorkspace(int $workspaceId): string
    {
        return (string) Uuid::v5(
            Uuid::fromString(Uuid::NAMESPACE_URL),
            'hasem:egs-unit:default:workspace:'.$workspaceId,
        );
    }

    public function securityRecords(): HasMany
    {
        return $this->hasMany(EInvoiceSecurityRecord::class, 'egs_unit_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
