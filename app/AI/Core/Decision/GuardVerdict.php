<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

enum GuardVerdict: string
{
    case Pass = 'pass';
    case Repaired = 'repaired';
    case Rejected = 'rejected';
}
