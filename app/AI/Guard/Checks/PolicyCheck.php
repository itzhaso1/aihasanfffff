<?php

declare(strict_types=1);

namespace App\AI\Guard\Checks;

use App\AI\Core\Data\GuardInput;
use App\AI\Guard\Contracts\GuardCheck;
use App\AI\Guard\GuardFinding;
use App\AI\Guard\GuardLimits;

final class PolicyCheck implements GuardCheck
{
    public const NAME = 'policy';

    public function __construct(
        private readonly GuardLimits $limits = new GuardLimits,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function inspect(GuardInput $input, string $text): GuardFinding
    {
        unset($input);

        foreach ($this->limits->failureMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return GuardFinding::rejected(self::NAME, 'failure_marker');
            }
        }

        if ($this->claimsFinanceMutation($text)) {
            return GuardFinding::rejected(self::NAME, 'forbidden_finance_claim');
        }

        return GuardFinding::pass(self::NAME, $text);
    }

    private function claimsFinanceMutation(string $text): bool
    {
        return preg_match(
            '/refund(?:ed)?|general ledger|(?<![A-Za-z])gl(?![A-Za-z])|posted (?:the )?invoice|cashier|pos (?:charge|sale)|قيد محاسبي|سجلت في المحاسبة|استرجعت المبلغ/iu',
            $text,
        ) === 1;
    }
}
