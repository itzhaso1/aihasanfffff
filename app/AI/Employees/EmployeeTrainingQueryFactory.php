<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Training\DefaultTrainingQueryFactory;
use App\AI\Training\TrainingQuery;
use App\AI\Training\TrainingQueryFactory;

/**
 * Training query from the employee record: its training profile, skills, role and
 * communication (language, dialect). Selection itself stays in the training library.
 */
final class EmployeeTrainingQueryFactory implements TrainingQueryFactory
{
    public function __construct(private readonly EmployeeRepository $employees) {}

    public function make(AiRequest $request, ResolvedEmployee $employee, ContextPayload $context): TrainingQuery
    {
        $record = $this->employees->find($request->workspaceId, $employee->employeeId);
        $persona = $record?->persona;

        return new TrainingQuery(
            profile: $record?->training_profile ?? DefaultTrainingQueryFactory::DEFAULT_PROFILE,
            skills: $employee->skillKeys,
            intents: $context->intent !== null ? [$context->intent] : [],
            roles: $record !== null ? [(string) $record->role] : [],
            dialect: $this->selector($persona?->dialect),
            language: $this->selector($persona?->language),
        );
    }

    private function selector(?string $value): ?string
    {
        return $value === null || $value === EmployeePersonaOptions::AUTO ? null : $value;
    }
}
