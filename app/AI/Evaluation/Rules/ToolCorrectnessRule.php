<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Rules;

use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;

final class ToolCorrectnessRule implements EvaluationRule
{
    public const NAME = 'tool_correctness';

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        $failed = [];
        $unoffered = [];
        foreach ($request->decision->toolCalls as $call) {
            if ($call->errorCode === ToolCallRecord::NOT_OFFERED) {
                $unoffered[] = $call->toolName;

                continue;
            }
            if (! $call->success) {
                $failed[] = $call->toolName;
            }
        }

        $findings = [];
        if ($unoffered !== []) {
            $findings[] = new EvaluationFinding(self::NAME, EvaluationCategory::ToolAction, EvaluationSeverity::Fail, 'tool_not_offered');
        }
        if ($failed !== []) {
            $severity = $this->replyClaimsCatalogSuccess($request->candidateText())
                ? EvaluationSeverity::Fail
                : EvaluationSeverity::Warn;
            $findings[] = new EvaluationFinding(
                self::NAME,
                EvaluationCategory::ToolAction,
                $severity,
                $severity === EvaluationSeverity::Fail ? 'tool_result_mismatch' : 'failed_tool',
            );
        }

        return $findings;
    }

    private function replyClaimsCatalogSuccess(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return preg_match('/سعر(?:ه|ها)?|ريال|متوفر|موجود|\d+(?:[.,]\d+)?\s*(?:sar|usd|\$)/iu', $text) === 1;
    }
}
