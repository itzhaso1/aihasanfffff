<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\Rules\GuardCheckRule;
use App\AI\Evaluation\Rules\GuardSignalRule;
use App\AI\Evaluation\Rules\PersonaAlignmentRule;
use App\AI\Evaluation\Rules\ResponseQualityRule;
use App\AI\Evaluation\Rules\RuntimeStatusRule;
use App\AI\Evaluation\Rules\ToolCorrectnessRule;
use App\AI\Guard\Checks\ActionClaimCheck;
use App\AI\Guard\Checks\PolicyCheck;
use App\AI\Guard\Checks\SecurityCheck;
use App\AI\Guard\Checks\StyleCheck;
use App\AI\Guard\Checks\TruthCheck;

/**
 * Deterministic, side-effect-free evaluator for a guarded Hasim run.
 * Inbox runs it observationally after the guard; results are not persisted
 * and never change training or employees.
 */
final class HasimEvaluator
{
    public const EVALUATOR = 'hasim';

    public const VERSION = '1';

    /** @var list<EvaluationRule> */
    private array $rules;

    /**
     * @param  list<EvaluationRule>|null  $rules
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? [
            new GuardSignalRule,
            new GuardCheckRule(new SecurityCheck, EvaluationCategory::Security),
            new GuardCheckRule(new TruthCheck, EvaluationCategory::Truth),
            new GuardCheckRule(new ActionClaimCheck, EvaluationCategory::ToolAction),
            new GuardCheckRule(new PolicyCheck, EvaluationCategory::Policy),
            new GuardCheckRule(new StyleCheck, EvaluationCategory::Quality),
            new ToolCorrectnessRule,
            new PersonaAlignmentRule,
            new ResponseQualityRule,
            new RuntimeStatusRule,
        ];
    }

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        $findings = [];
        foreach ($this->rules as $rule) {
            array_push($findings, ...$rule->evaluate($request));
        }

        return EvaluationResult::fromFindings(self::EVALUATOR, self::VERSION, $findings);
    }
}
