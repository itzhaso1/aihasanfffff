<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerDisputeReporterType: string
{
    case Worker = 'worker';

    case Company = 'company';

    case PlatformAdmin = 'platform_admin';

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'العامل',
            self::Company => 'الشركة',
            self::PlatformAdmin => 'إدارة المنصة',
        };
    }
}
