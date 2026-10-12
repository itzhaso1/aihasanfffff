<?php

declare(strict_types=1);

namespace App\AI\Employees\Resolution;

use App\AI\Core\AiRequest;

final class RequestedEmployeeSource implements EmployeeCandidateSource
{
    public function candidate(AiRequest $request): ?int
    {
        return $request->employeeId;
    }

    public function allowsInactiveInSandbox(): bool
    {
        return true;
    }
}
