<?php

declare(strict_types=1);

namespace App\AI\Employees\Preview;

use App\AI\Core\Contracts\ResponseGuard;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\GuardOutcome;
use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\HasimResponseGuard;

/**
 * Preview stays non-mutating, but it applies the same safety checks as inbox.
 * A rejected or leaked model draft is replaced with a customer-facing line.
 * The replacement follows the run mode; it is not one sentence for every state.
 */
final class PreviewResponseGuard implements ResponseGuard
{
    public const NAME = 'preview_customer_safe';

    public function __construct(private readonly HasimResponseGuard $guard = new HasimResponseGuard) {}

    public function review(GuardInput $input): GuardOutcome
    {
        $inner = $this->guard->review($input);
        $text = (string) ($inner->text ?? '');
        if ($inner->verdict === GuardVerdict::Rejected || $this->leaks($text) || $this->missesKnownDataGap($input, $text)) {
            return new GuardOutcome(
                GuardVerdict::Repaired,
                $this->customerLine($input),
                [
                    ...$inner->results,
                    new GuardResult(self::NAME, GuardVerdict::Repaired, 'preview_customer_safe'),
                ],
            );
        }

        return $inner;
    }

    private function leaks(string $text): bool
    {
        $needles = [
            'Customer-facing text only',
            'Constitution',
            'Persona identity',
            'business_capabilities',
            'not_loaded',
            'Social Style References',
            'STYLE references',
            'app/AI',
            'Output:',
            'SYSTEM:',
            'functionCall',
            'tool schema',
        ];
        foreach ($needles as $needle) {
            if (stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function missesKnownDataGap(GuardInput $input, string $text): bool
    {
        if ($this->topic($input) === null || $this->dataState($input) === 'available') {
            return false;
        }

        foreach (['محمّل', 'محمل', 'ما لقيت', 'ما عندي', 'ما أقدر', 'ما اقدر', 'غير متاح', 'وضع اختبار', 'ما قدرت'] as $needle) {
            if (str_contains($text, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function dataState(GuardInput $input): string
    {
        foreach ($input->facts as $fact) {
            if ($fact->key === 'result_status' && $fact->value === 'available') {
                return 'available';
            }
            if (str_ends_with($fact->key, '.name') && is_string($fact->value) && trim($fact->value) !== '') {
                return 'available';
            }
        }
        foreach ($input->toolCalls as $call) {
            if ($call->success) {
                continue;
            }
            if (in_array($call->errorCode, ['permission_denied', 'skill_denied', 'disabled', 'workspace_denied'], true)) {
                return 'unavailable';
            }

            return 'failed';
        }
        foreach ($input->facts as $fact) {
            if ($fact->key === 'result_status' && $fact->value === 'empty') {
                return 'empty';
            }
        }

        return 'not_loaded';
    }

    private function topic(GuardInput $input): ?string
    {
        $ask = mb_strtolower($input->request->message->text);
        if ($this->asks($ask, ['تقدر تسوي'])) {
            return null;
        }
        if ($this->asks($ask, ['خدم'])) {
            return 'services';
        }
        if ($this->asks($ask, ['منتج'])) {
            return 'products';
        }
        if ($this->asks($ask, ['سعر', 'اسعار', 'أسعار'])) {
            return 'pricing';
        }
        if ($this->asks($ask, ['خصم', 'خصوم'])) {
            return 'discounts';
        }
        if ($this->asks($ask, ['توصل', 'توصيل'])) {
            return 'delivery';
        }
        if ($this->asks($ask, ['موقع', 'وين'])) {
            return 'location';
        }
        if ($this->asks($ask, ['دوام', 'اوقات', 'أوقات'])) {
            return 'hours';
        }

        return null;
    }

    private function customerLine(GuardInput $input): string
    {
        $ask = mb_strtolower($input->request->message->text);
        $state = $this->dataState($input);
        $test = $input->request->runMode->isSandboxed();
        if ($state === 'failed') {
            return 'ما قدرت أتحقق من المعلومة الحين.';
        }
        if ($state === 'unavailable') {
            return 'هالمعلومة غير متاحة حالياً.';
        }
        $empty = $state === 'empty';

        if ($this->asks($ask, ['خدم'])) {
            if ($empty) {
                return 'ما فيه خدمات مضافة حالياً.';
            }

            return $test
                ? 'انت الحين في وضع اختبار، وما عندي خدمات محمّلة أقدر أعرضها.'
                : 'بيانات الخدمات غير محمّلة، فما أقدر أعرضها.';
        }
        if ($this->asks($ask, ['منتج', 'منتجات'])) {
            if ($empty) {
                return 'ما فيه منتجات مضافة حالياً.';
            }

            return $test
                ? 'انت الحين في وضع اختبار، وما عندي منتجات محمّلة.'
                : 'بيانات المنتجات غير محمّلة، فما أقدر أعرضها.';
        }
        if ($this->asks($ask, ['سعر', 'اسعار', 'أسعار'])) {
            return $test
                ? 'الأسعار غير محمّلة في وضع الاختبار، فما أقدر أعطيك رقم.'
                : 'ما أقدر أأكد السعر لأن ما عندي نتيجة بحث.';
        }
        if ($this->asks($ask, ['خصم', 'خصوم'])) {
            return $test
                ? 'الخصومات غير محمّلة في وضع الاختبار.'
                : 'ما لقيت خصم مؤكد أقدر أقوله.';
        }
        if ($this->asks($ask, ['توصل', 'توصيل'])) {
            return $test
                ? 'معلومات التوصيل غير محمّلة في وضع الاختبار.'
                : 'ما أقدر أأكد موعد التوصيل من البيانات المتوفرة.';
        }
        if ($this->asks($ask, ['موقع', 'وين'])) {
            return $test
                ? 'الموقع غير محمّل عندي في وضع الاختبار.'
                : 'ما عندي موقع مؤكد أقدر أرسله.';
        }
        if ($this->asks($ask, ['دوام', 'اوقات', 'أوقات'])) {
            return $test
                ? 'أوقات الدوام غير محمّلة في وضع الاختبار.'
                : 'ما عندي أوقات دوام مؤكدة.';
        }
        if ($this->asks($ask, ['تقدر تسوي', 'تقدر تسوي'])) {
            return 'أقدر أساعدك في المحادثة، وبيانات النشاط غير محمّلة الحين.';
        }

        return $test
            ? 'ما أقدر أأكد هالمعلومة لأن بيانات النشاط غير محمّلة في الاختبار.'
            : 'ما أقدر أتحقق من هالمعلومة حالياً.';
    }

    /**
     * @param  list<string>  $needles
     */
    private function asks(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
