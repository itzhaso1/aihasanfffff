<?php

namespace App\Services\Inbox;

use App\Enums\ConversationHandoffReason;

final class InboxHumanHandoffDetector
{
    /**
     * @var list<string>
     */
    private const PHRASES = [
        'حولني لشخص',
        'حولني إلى شخص',
        'حولني لممثل',
        'حولني لموظف',
        'حولني إلى موظف',
        'حولني على الفريق',
        'حولني للفريق',
        'حولني إلى الفريق',
        'حولني الى الفريق',
        'حولني الي الفريق',
        'حولني لفريق',
        'حولني على موظف',
        'حولني للموظف',
        'حولني على شخص',
        'حولني لخدمة العملاء',
        'حولني على خدمة العملاء',
        'وصلني بالفريق',
        'بدي شخص',
        'بدي موظف',
        'بدي أحكي مع موظف',
        'بدي احكي مع موظف',
        'موظف لو سمحت',
        'بدي انسان',
        'بدي إنسان',
        'ابي انسان',
        'أبي إنسان',
        'ابغى انسان',
        'أريد موظف',
        'اريد موظف',
        'أريد شخص',
        'اريد شخص',
        'أريد التحدث مع موظف',
        'اريد التحدث مع موظف',
        'أريد التحدث مع إنسان',
        'أريد التحدث مع انسان',
        'اريد التحدث مع انسان',
        'اريد التحدث مع إنسان',
        'تكلم مع موظف',
        'تحدث مع موظف',
        'موظف حقيقي',
        'شخص حقيقي',
        'human agent',
        'talk to human',
        'talk to agent',
        'real person',
        'customer service',
    ];

    public function requestsHumanAgent(?string $content): bool
    {
        $normalized = $this->normalize($content);
        if ($normalized === '') {
            return false;
        }

        foreach (self::PHRASES as $phrase) {
            if (str_contains($normalized, $this->normalize($phrase))) {
                return true;
            }
        }

        if (preg_match('/حولن/u', $normalized) === 1
            && preg_match('/(فريق|موظف|شخص|انسان|ممثل|خدمه العملاء|خدمة العملاء)/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/(اكلم|اكلمه|احكي|اتحدث)/u', $normalized) === 1
            && preg_match('/(موظف|انسان|شخص|احد|فريق)/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(human|agent|representative)\b/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * The model promised a transfer. That promise is not a recorded handoff.
     */
    public function claimsUnconfirmedHandoff(?string $content): bool
    {
        $normalized = $this->normalize($content);
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/(بحولك|احولك|ساحولك|حولتك|تم تحويل|راح احولك|رح احولك)/u', $normalized) === 1) {
            return true;
        }

        if (str_contains($normalized, 'فريق') && preg_match('/(بيكمل|يكمل|بتابع|يتابع|معك هنا)/u', $normalized) === 1) {
            return true;
        }

        return str_contains($normalized, 'ابشر') && str_contains($normalized, 'فريق');
    }

    /**
     * A staff decision, not a catalog question. Unknown product questions stay with AI.
     */
    public function staffDecisionReason(?string $content): ?ConversationHandoffReason
    {
        $normalized = $this->normalize($content);
        if ($normalized === '') {
            return null;
        }

        $groups = [
            ConversationHandoffReason::RefundRequest->value => ['استرجاع', 'استرجع', 'استرداد', 'refund'],
            ConversationHandoffReason::CancelRequest->value => ['الغاء الطلب', 'إلغاء الطلب', 'الغاء طلبي', 'cancel my order'],
            ConversationHandoffReason::PaymentIssue->value => ['مشكلة دفع', 'ما وصل المبلغ', 'payment failed', 'دفعة معلقه'],
            ConversationHandoffReason::SensitiveSupport->value => ['شكوى', 'نزاع', 'complaint', 'مختص', 'فني يزور'],
            ConversationHandoffReason::AgentRequested->value => ['خصم خاص', 'وافق على السعر', 'موافقه خاصه', 'سعر خاص لي'],
        ];

        foreach ($groups as $reason => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, $this->normalize($phrase))) {
                    return ConversationHandoffReason::from($reason);
                }
            }
        }

        return null;
    }

    private function normalize(?string $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ئ', 'ؤ'], ['ا', 'ا', 'ا', 'ي', 'ي', 'و'], $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }
}
