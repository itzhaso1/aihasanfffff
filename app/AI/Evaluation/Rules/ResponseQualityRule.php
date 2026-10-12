<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Rules;

use App\AI\Core\AiResponseStatus;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;

final class ResponseQualityRule implements EvaluationRule
{
    public const NAME = 'response_quality';

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        $findings = [];
        $text = $request->candidateText();

        if ($request->decision->status === AiResponseStatus::Replied && $text === '') {
            $findings[] = new EvaluationFinding(self::NAME, EvaluationCategory::Quality, EvaluationSeverity::Fail, 'empty_output');
        }

        if ($request->finishReason === ModelFinishReason::Length && $text !== '') {
            $findings[] = new EvaluationFinding(self::NAME, EvaluationCategory::Quality, EvaluationSeverity::Warn, 'incomplete_response');
        }

        return $findings;
    }
}
