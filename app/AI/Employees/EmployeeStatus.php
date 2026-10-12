<?php

declare(strict_types=1);

namespace App\AI\Employees;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
