<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkforceParticipantType: string
{
    case CompanyStaff = 'company_staff';

    case IndependentWorker = 'independent_worker';

    case AiEmployee = 'ai_employee';

    public function label(): string
    {
        return match ($this) {
            self::CompanyStaff => 'موظف الشركة',
            self::IndependentWorker => 'عامل مستقل',
            self::AiEmployee => 'موظف AI',
        };
    }
}
