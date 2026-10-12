<?php

declare(strict_types=1);

namespace App\AI\Guard;

use App\AI\Core\Contracts\ResponseGuard;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\GuardOutcome;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\Checks\ActionClaimCheck;
use App\AI\Guard\Checks\PolicyCheck;
use App\AI\Guard\Checks\SecurityCheck;
use App\AI\Guard\Checks\StyleCheck;
use App\AI\Guard\Checks\TruthCheck;
use App\AI\Guard\Contracts\GuardCheck;

/**
 * Final safety and quality gate for the Hasim Runtime. Side-effect free.
 * Inbox applies it through HasimInboxRuntime; preview still uses PreviewResponseGuard.
 */
final class HasimResponseGuard implements ResponseGuard
{
    /** @var list<GuardCheck> */
    private array $checks;

    /**
     * @param  list<GuardCheck>|null  $checks
     */
    public function __construct(
        ?array $checks = null,
        GuardLimits $limits = new GuardLimits,
    ) {
        $this->checks = $checks ?? [
            new SecurityCheck,
            new TruthCheck,
            new ActionClaimCheck,
            new PolicyCheck($limits),
            new StyleCheck(limits: $limits),
        ];
    }

    public function review(GuardInput $input): GuardOutcome
    {
        $text = $input->text;
        $results = [];
        $verdict = GuardVerdict::Pass;

        if (trim($text) === '') {
            return new GuardOutcome(GuardVerdict::Rejected, null, [
                GuardFinding::rejected(StyleCheck::NAME, 'empty_output')->toResult(),
            ]);
        }

        foreach ($this->checks as $check) {
            $finding = $check->inspect($input, $text);
            $results[] = $finding->toResult();
            if ($finding->verdict === GuardVerdict::Rejected) {
                return new GuardOutcome(GuardVerdict::Rejected, null, $results);
            }
            if ($finding->verdict === GuardVerdict::Repaired) {
                $text = (string) $finding->text;
                $verdict = GuardVerdict::Repaired;
            }
        }

        return new GuardOutcome($verdict, $text, $results);
    }
}
