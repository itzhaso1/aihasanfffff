<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Rules;

use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;
use App\AI\Guard\Checks\ActionClaimCheck;
use App\AI\Guard\Checks\PolicyCheck;
use App\AI\Guard\Checks\SecurityCheck;
use App\AI\Guard\Checks\StyleCheck;
use App\AI\Guard\Checks\TruthCheck;

final class GuardSignalRule implements EvaluationRule
{
    public const NAME = 'guard_signal';

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        $findings = [];
        foreach ($request->decision->guardResults as $result) {
            $finding = $this->fromResult($result);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function fromResult(GuardResult $result): ?EvaluationFinding
    {
        if ($result->verdict === GuardVerdict::Pass) {
            if ($result->reasonCode === 'guard_not_implemented') {
                return new EvaluationFinding(self::NAME, EvaluationCategory::Runtime, EvaluationSeverity::Warn, 'evaluation_incomplete');
            }

            return null;
        }

        $severity = $result->verdict === GuardVerdict::Rejected
            ? EvaluationSeverity::Fail
            : EvaluationSeverity::Warn;

        return new EvaluationFinding(
            self::NAME,
            $this->category($result->guard),
            $severity,
            $result->reasonCode ?? $result->verdict->value,
        );
    }

    private function category(string $guard): EvaluationCategory
    {
        return match ($guard) {
            TruthCheck::NAME => EvaluationCategory::Truth,
            SecurityCheck::NAME => EvaluationCategory::Security,
            ActionClaimCheck::NAME => EvaluationCategory::ToolAction,
            PolicyCheck::NAME => EvaluationCategory::Policy,
            StyleCheck::NAME => EvaluationCategory::Quality,
            default => EvaluationCategory::Runtime,
        };
    }
}
