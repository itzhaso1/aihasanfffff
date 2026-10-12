<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Rules;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponseStatus;
use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;

final class RuntimeStatusRule implements EvaluationRule
{
    public const NAME = 'runtime_status';

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        if ($request->decision->status !== AiResponseStatus::Failed || $request->decision->failure === null) {
            return [];
        }
        if ($request->decision->failure->code === AiFailureCode::GuardRejected) {
            return [];
        }

        return [new EvaluationFinding(
            self::NAME,
            EvaluationCategory::Runtime,
            EvaluationSeverity::Fail,
            $request->decision->failure->code->value,
        )];
    }
}
