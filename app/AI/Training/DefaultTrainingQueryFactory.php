<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;

/**
 * Query from what the core already carries: employee skills and the context intent,
 * with the default profile. Communication selectors arrive with the employee layer.
 */
final class DefaultTrainingQueryFactory implements TrainingQueryFactory
{
    public const DEFAULT_PROFILE = 'default';

    public function make(AiRequest $request, ResolvedEmployee $employee, ContextPayload $context): TrainingQuery
    {
        return new TrainingQuery(
            profile: self::DEFAULT_PROFILE,
            skills: $employee->skillKeys,
            intents: $context->intent !== null ? [$context->intent] : [],
        );
    }
}
