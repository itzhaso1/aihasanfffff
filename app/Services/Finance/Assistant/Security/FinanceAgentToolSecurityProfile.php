<?php

namespace App\Services\Finance\Assistant\Security;

use App\Enums\Finance\FinanceAgentToolAccessMode;
use App\Enums\Finance\FinanceAgentToolRiskLevel;

final class FinanceAgentToolSecurityProfile
{
    public function __construct(
        public readonly string $name,
        public readonly string $permission,
        public readonly FinanceAgentToolRiskLevel $riskLevel,
        public readonly FinanceAgentToolAccessMode $accessMode,
        public readonly int $maxCallsPerRun,
        public readonly string $auditCategory,
        public readonly string $resultProfile,
    ) {}
}
