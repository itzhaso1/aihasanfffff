<?php

declare(strict_types=1);

namespace App\AI\Employees\Resolution;

use App\AI\Core\AiRequest;
use App\AI\Employees\EmployeeRepository;

final class DefaultEmployeeSource implements EmployeeCandidateSource
{
    public function __construct(private readonly EmployeeRepository $employees) {}

    public function candidate(AiRequest $request): ?int
    {
        $default = $this->employees->findDefault($request->workspaceId);

        return $default === null ? null : (int) $default->id;
    }

    public function allowsInactiveInSandbox(): bool
    {
        return false;
    }
}
