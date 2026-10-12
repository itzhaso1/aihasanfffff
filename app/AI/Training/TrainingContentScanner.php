<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Detects data that must never live in training.
 *
 * Every type is checked for secrets, card numbers, one-time codes, emails and phone
 * numbers. Knowledge documents are additionally checked for live transactional facts
 * (amounts with a currency, stock counts, order references), because knowledge is read
 * as stable fact. Examples and scenarios may show illustrative prices in replies.
 */
final class TrainingContentScanner
{
    private const SECRET_PATTERNS = [
        '/sk-[A-Za-z0-9_\-]{16,}/',
        '/AIza[0-9A-Za-z_\-]{20,}/',
        '/Bearer\s+[A-Za-z0-9._\-]{10,}/i',
        '/whsec_[A-Za-z0-9]{8,}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    private const ONE_TIME_CODE = '/(otp|one[- ]time|verification code|رمز التحقق|كود التحقق|رمز التفعيل)\D{0,15}\d{4,8}/iu';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@(?!example\.(com|org|net)\b)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const PHONE = '/(?<!\d)(\+?9665\d{8}|05\d{8}|\+\d{10,14})(?!\d)/';

    private const CARD_CANDIDATE = '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/';

    private const LIVE_FACT_PATTERNS = [
        'price' => '/\d[\d,.]*\s*(ريال|ر\.س|درهم|دينار|sar|sr|aed|usd|\$)|(sar|usd|\$)\s*\d/iu',
        'stock' => '/\d+\s*(قطعة|قطع|حبة|حبات|pieces?|units?|in stock|left)\b|(stock|المخزون|متبقي|باقي منه)\D{0,12}\d+/iu',
        'order' => '/(order|طلب|الطلب|طلبك)\s*(رقم|number|no\.?|#)\s*[:#]?\s*\d{3,}|#\d{4,}/iu',
    ];

    /**
     * @return list<TrainingLintIssue>
     */
    public function scan(TrainingDocument $document): array
    {
        $issues = [];
        foreach ($this->sensitiveFindings($document->content) as $finding) {
            $issues[] = new TrainingLintIssue(TrainingIssueCode::SensitiveData, $document->source, 'Contains '.$finding.'.');
        }
        if ($document->type === TrainingType::Knowledge) {
            foreach (self::LIVE_FACT_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $document->content) === 1) {
                    $issues[] = new TrainingLintIssue(TrainingIssueCode::LiveBusinessFact, $document->source, 'Knowledge holds a live '.$label.' fact; it belongs to business services.');
                }
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function sensitiveFindings(string $content): array
    {
        $findings = [];
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $findings[] = 'a secret or credential';

                break;
            }
        }
        if (preg_match(self::ONE_TIME_CODE, $content) === 1) {
            $findings[] = 'a one-time code';
        }
        if (preg_match(self::EMAIL, $content) === 1) {
            $findings[] = 'an email address';
        }
        if (preg_match(self::PHONE, $content) === 1) {
            $findings[] = 'a phone number';
        }
        if ($this->containsCardNumber($content)) {
            $findings[] = 'a payment card number';
        }

        return $findings;
    }

    private function containsCardNumber(string $content): bool
    {
        preg_match_all(self::CARD_CANDIDATE, $content, $matches);
        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';
            if (strlen($digits) >= 13 && strlen($digits) <= 19 && $this->passesLuhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
