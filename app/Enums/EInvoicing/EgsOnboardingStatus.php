<?php

namespace App\Enums\EInvoicing;

enum EgsOnboardingStatus: string
{
    case Unconfigured = 'unconfigured';
    case CsrGenerated = 'csr_generated';
    case CompliancePending = 'compliance_pending';
    case ComplianceActive = 'compliance_active';
    case ProductionPending = 'production_pending';
    case ProductionActive = 'production_active';
    case RotationRequired = 'rotation_required';
    case Failed = 'failed';
    case Revoked = 'revoked';

    public function allowsCsrGeneration(): bool
    {
        return $this === self::Unconfigured || $this === self::Failed;
    }
}
