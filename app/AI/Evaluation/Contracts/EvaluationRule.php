<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Contracts;

use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;

interface EvaluationRule
{
    public function name(): string;

    /**
     * @return list<EvaluationFinding>
     */
    public function evaluate(EvaluationRequest $request): array;
}
