<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Rules;

use App\AI\Core\Decision\GuardVerdict;
use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;
use App\AI\Guard\Contracts\GuardCheck;

final class GuardCheckRule implements EvaluationRule
{
    public function __construct(
        private readonly GuardCheck $check,
        private readonly EvaluationCategory $category,
    ) {}

    public function name(): string
    {
        return $this->check->name();
    }

    public function evaluate(EvaluationRequest $request): array
    {
        if ($request->candidateText() === '') {
            return [];
        }

        foreach ($request->decision->guardResults as $result) {
            if ($result->guard === $this->check->name() && $result->verdict !== GuardVerdict::Pass) {
                return [];
            }
        }

        $finding = $this->check->inspect($request->guardInput(), $request->candidateText());
        if ($finding->verdict === GuardVerdict::Pass) {
            return [];
        }

        return [new EvaluationFinding(
            $this->name(),
            $this->category,
            $finding->verdict === GuardVerdict::Rejected ? EvaluationSeverity::Fail : EvaluationSeverity::Warn,
            (string) $finding->reasonCode,
        )];
    }
}
