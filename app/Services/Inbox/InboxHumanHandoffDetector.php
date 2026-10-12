<?php

namespace App\Services\Inbox;

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
        'بدي شخص',
        'بدي موظف',
        'بدي أحكي مع موظف',
        'بدي احكي مع موظف',
        'موظف لو سمحت',
        'بدي انسان',
        'بدي إنسان',
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

        if (preg_match('/\b(human|agent|representative)\b/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return str_replace(['أ', 'إ', 'آ'], 'ا', $text);
    }
}
