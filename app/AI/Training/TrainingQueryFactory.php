<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;

/**
 * Translates runtime data into a training query. The employee layer will provide the
 * implementation that knows profile, dialect, language, role and domain.
 */
interface TrainingQueryFactory
{
    public function make(AiRequest $request, ResolvedEmployee $employee, ContextPayload $context): TrainingQuery;
}
