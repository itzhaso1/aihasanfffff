<?php

declare(strict_types=1);

namespace App\AI\Guard\Checks;

use App\AI\Core\Data\GuardInput;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Guard\Contracts\GuardCheck;
use App\AI\Guard\GuardFinding;
use App\AI\Tools\CanonicalTools;

final class ActionClaimCheck implements GuardCheck
{
    public const NAME = 'action_claim';

    public function name(): string
    {
        return self::NAME;
    }

    public function inspect(GuardInput $input, string $text): GuardFinding
    {
        $confirmed = array_map(
            static fn (ConfirmedAction $action): string => $action->type,
            $input->confirmedActions,
        );

        foreach ($this->claims($text) as $type) {
            if (! in_array($type, $confirmed, true)) {
                return GuardFinding::rejected(self::NAME, 'unconfirmed_action');
            }
        }

        return GuardFinding::pass(self::NAME, $text);
    }

    /**
     * @return list<string>
     */
    private function claims(string $text): array
    {
        $claims = [];
        if ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:saved|stored)\b/i',
            '/حفظت|تم الحفظ|سجلت القياس/u',
        ])) {
            $claims[] = CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT;
        }
        if ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:transferred|escalated|handed (?:you|this) over)\b/i',
            '/(?:حولت(?:ك|كم|ها|ه)?|تم\s+تحويل(?:ك|كم|المحادثة|الطلب)?)\s+(?:ل|إلى|الى|على)\s*(?:الفريق|الموظف|موظف|خدمة\s+العملاء|الدعم|شخص)/u',
        ])) {
            $claims[] = CanonicalTools::TRANSFER_TO_HUMAN_AGENT;
        }
        if ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:refunded|cancelled|canceled|charged|posted|placed the order)\b/i',
            '/ألغيت|رجعت المبلغ|حولت\s+(?:المبلغ|الفلوس|الرصيد|الحوالة)|تم الاسترجاع|تم الإلغاء|أرسلت الفاتورة|أنشأت فاتورة/u',
        ])) {
            $claims[] = 'forbidden_mutation';
        }

        return $claims;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
