<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\TrainingSelection;

interface TrainingProvider
{
    /** Selects only the training relevant to this run, never the whole library. Read-only. */
    public function select(AiRequest $request, ResolvedEmployee $employee, ContextPayload $context): TrainingSelection;
}
