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

        foreach ($this->claimRequirements($text) as $acceptableTypes) {
            if (array_intersect($acceptableTypes, $confirmed) === []) {
                return GuardFinding::rejected(self::NAME, 'unconfirmed_action');
            }
        }

        return GuardFinding::pass(self::NAME, $text);
    }

    /**
     * @return list<list<string>>
     */
    private function claimRequirements(string $text): array
    {
        $requirements = [];
        if ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:saved|stored)\b/i',
            '/حفظت|تم الحفظ|سجلت القياس/u',
        ])) {
            $requirements[] = [CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT];
        }
        if ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:transferred|escalated|handed (?:you|this) over)\b/i',
            '/(?:حولت(?:ك|كم|ها|ه)?|تم\s+تحويل(?:ك|كم|المحادثة|الطلب)?)\s+(?:ل|إلى|الى|على)\s*(?:الفريق|الموظف|موظف|خدمة\s+العملاء|الدعم|شخص)/u',
        ])) {
            $requirements[] = [CanonicalTools::TRANSFER_TO_HUMAN_AGENT];
        }
        if ($this->matches($text, [
            '/تم\s+(?:تأكيد|إنشاء|تسجيل|اعتماد)\s+(?:الحجز|حجزك|الموعد)|حجزت\s+لك|\bbooking\s+(?:is\s+)?confirmed\b/iu',
        ])) {
            $requirements[] = [CanonicalTools::CREATE_BOOKING, CanonicalTools::RESCHEDULE_BOOKING];
        }
        if ($this->matches($text, [
            '/تم\s+(?:تأجيل|إعادة\s+جدولة|تعديل\s+موعد)\s+(?:الحجز|حجزك|الموعد)|أجلت\s+(?:الحجز|حجزك|الموعد)/u',
        ])) {
            $requirements[] = [CanonicalTools::RESCHEDULE_BOOKING, CanonicalTools::UPDATE_BOOKING];
        }
        if ($this->matches($text, [
            '/تم\s+إلغاء\s+(?:الحجز|حجزك|الموعد)|ألغيت\s+(?:الحجز|حجزك|الموعد)/u',
        ])) {
            $requirements[] = [CanonicalTools::CANCEL_BOOKING];
        } elseif ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:cancelled|canceled)\b/i',
            '/ألغيت|تم الإلغاء/u',
        ])) {
            $requirements[] = [CanonicalTools::CANCEL_CUSTOMER_ORDER, CanonicalTools::CANCEL_BOOKING];
        }
        if ($this->matches($text, [
            '/\bI(?:\'ve| have)? (?:just )?(?:refunded|charged|posted|placed the order)\b/i',
            '/رجعت المبلغ|حولت\s+(?:المبلغ|الفلوس|الرصيد|الحوالة)|تم الاسترجاع|أرسلت الفاتورة|أنشأت فاتورة/u',
        ])) {
            $requirements[] = ['forbidden_mutation'];
        }

        return $requirements;
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
