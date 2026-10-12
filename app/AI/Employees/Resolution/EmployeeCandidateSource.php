<?php

declare(strict_types=1);

namespace App\AI\Employees\Resolution;

use App\AI\Core\AiRequest;

/**
 * One way to name the employee for a run. The resolver asks sources in order and uses
 * the first candidate that is a usable employee of the request workspace.
 */
interface EmployeeCandidateSource
{
    public function candidate(AiRequest $request): ?int;

    /** Explicit sources may name an inactive employee for preview and evaluation runs. */
    public function allowsInactiveInSandbox(): bool;
}
