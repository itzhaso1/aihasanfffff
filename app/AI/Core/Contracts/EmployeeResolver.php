<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;

interface EmployeeResolver
{
    /**
     * Resolves the employee by id: the request employee id when set, otherwise the
     * assignment rules of the owning layer. Must be read-only in every run mode.
     */
    public function resolve(AiRequest $request): ResolvedEmployee;
}
