<?php

declare(strict_types=1);

namespace App\AI\Guard\Checks;

use App\AI\Core\Data\GuardInput;
use App\AI\Core\Decision\FactRecord;
use App\AI\Guard\Contracts\GuardCheck;
use App\AI\Guard\GuardFinding;

final class TruthCheck implements GuardCheck
{
    public const NAME = 'truth';

    public function name(): string
    {
        return self::NAME;
    }

    public function inspect(GuardInput $input, string $text): GuardFinding
    {
        $claimedPrices = $this->claimedPrices($text);
        $trustedPrices = $this->trustedValues($input->facts, 'price');
        if ($claimedPrices !== []) {
            if ($trustedPrices === []) {
                return GuardFinding::rejected(self::NAME, 'invented_price');
            }
            foreach ($claimedPrices as $amount) {
                if (! in_array($amount, $trustedPrices, true)) {
                    return GuardFinding::rejected(self::NAME, 'ungrounded_price');
                }
            }
        }

        $availability = $this->trustedStrings($input->facts, 'availability');
        if ($availability !== [] && $this->availabilityConflicts($text, $availability)) {
            return GuardFinding::rejected(self::NAME, 'availability_contradiction');
        }

        if ($availability === [] && $this->assertsProductAvailability($text)) {
            return GuardFinding::rejected(self::NAME, 'ungrounded_availability');
        }

        if ($this->stockConflicts($text, $input->facts)) {
            return GuardFinding::rejected(self::NAME, 'ungrounded_stock');
        }

        if ($this->currencyConflicts($text, $input->facts)) {
            return GuardFinding::rejected(self::NAME, 'ungrounded_currency');
        }

        if ($this->inventedOffering($input, $text)) {
            return GuardFinding::rejected(self::NAME, 'invented_offering');
        }

        return GuardFinding::pass(self::NAME, $text);
    }

    /**
     * @return list<string>
     */
    private function claimedPrices(string $text): array
    {
        $matches = [];
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:ريال|ر\.س|sar|usd|\$)/iu', $text, $currencyAfter);
        preg_match_all('/(?:ريال|ر\.س|sar|\$)\s*(\d+(?:[.,]\d+)?)/iu', $text, $currencyBefore);
        preg_match_all('/(?:سعر(?:ه|ها)?|price)\s*:?\s*(\d+(?:[.,]\d+)?)/iu', $text, $labeled);
        foreach ([$currencyAfter[1] ?? [], $currencyBefore[1] ?? [], $labeled[1] ?? []] as $group) {
            foreach ($group as $raw) {
                $normalized = $this->normalizeAmount((string) $raw);
                if ($normalized !== null) {
                    $matches[] = $normalized;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param  list<FactRecord>  $facts
     * @return list<string>
     */
    private function trustedValues(array $facts, string $suffix): array
    {
        $values = [];
        foreach ($facts as $fact) {
            if (! str_ends_with(strtolower($fact->key), '.'.$suffix) && strtolower($fact->key) !== $suffix) {
                continue;
            }
            $normalized = $this->normalizeAmount(is_scalar($fact->value) || $fact->value === null ? (string) $fact->value : '');
            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<FactRecord>  $facts
     * @return list<string>
     */
    private function trustedStrings(array $facts, string $suffix): array
    {
        $values = [];
        foreach ($facts as $fact) {
            if (! str_ends_with(strtolower($fact->key), '.'.$suffix) && strtolower($fact->key) !== $suffix) {
                continue;
            }
            if (! is_string($fact->value) && ! is_bool($fact->value)) {
                continue;
            }
            $values[] = is_bool($fact->value) ? ($fact->value ? 'in_stock' : 'out_of_stock') : strtolower(trim($fact->value));
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<string>  $availability
     */
    private function availabilityConflicts(string $text, array $availability): bool
    {
        $unique = array_values(array_unique($availability));
        $claimsOut = preg_match('/غير متوفر|نفد|out[_ ]of[_ ]stock/iu', $text) === 1;
        $claimsInStock = ! $claimsOut && preg_match('/متوفر|موجود|in[_ ]stock/iu', $text) === 1;

        if ($unique === ['out_of_stock'] && $claimsInStock) {
            return true;
        }

        return $unique === ['in_stock'] && $claimsOut;
    }

    private function assertsProductAvailability(string $text): bool
    {
        return preg_match(
            '/(?:المنتج|الصنف|المخزون|القطعة).{0,24}(?:متوفر|غير متوفر|نفد)|(?:متوفر|غير متوفر|نفد)\s*(?:الحين|الآن)|(?:in|out)[_ ]of[_ ]stock/iu',
            $text,
        ) === 1;
    }

    /**
     * @param  list<FactRecord>  $facts
     */
    private function stockConflicts(string $text, array $facts): bool
    {
        $claimed = $this->claimedStock($text);
        if ($claimed === []) {
            return false;
        }

        $trusted = [];
        foreach ($facts as $fact) {
            if (! str_ends_with(strtolower($fact->key), '.stock') && strtolower($fact->key) !== 'stock') {
                continue;
            }
            if (is_int($fact->value) || (is_string($fact->value) && is_numeric($fact->value))) {
                $trusted[] = (string) (int) $fact->value;
            }
        }

        foreach ($claimed as $amount) {
            if (! in_array($amount, $trusted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function claimedStock(string $text): array
    {
        $matches = [];
        preg_match_all('/(?:كمية|مخزون)\s*[:：]?\s*(\d+)/u', $text, $labeled);
        preg_match_all('/(\d+)\s*(?:قطع|قطعة|حبات|حبة)/u', $text, $units);
        foreach ([...($labeled[1] ?? []), ...($units[1] ?? [])] as $raw) {
            if (is_numeric($raw)) {
                $matches[] = (string) (int) $raw;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param  list<FactRecord>  $facts
     */
    private function currencyConflicts(string $text, array $facts): bool
    {
        $claimed = [];
        if (preg_match('/\b(SAR|USD|AED|EUR|GBP)\b/', $text, $match) === 1) {
            $claimed[] = strtoupper($match[1]);
        }
        if ($claimed === []) {
            return false;
        }

        $trusted = [];
        foreach ($facts as $fact) {
            if (! str_ends_with(strtolower($fact->key), '.currency') || ! is_string($fact->value)) {
                continue;
            }
            $trusted[] = strtoupper(trim($fact->value));
        }

        foreach ($claimed as $code) {
            if (! in_array($code, $trusted, true)) {
                return true;
            }
        }

        return false;
    }

    private function inventedOffering(GuardInput $input, string $text): bool
    {
        if ($this->hasCatalogEvidence($input)) {
            return false;
        }

        $listsOfferings = preg_match('/عندنا|نقدم|نوفر|خدماتنا/u', $text) === 1
            && (substr_count($text, '،') >= 1 || substr_count($text, ',') >= 1);

        return $listsOfferings;
    }

    private function hasCatalogEvidence(GuardInput $input): bool
    {
        foreach ($input->facts as $fact) {
            if (str_ends_with($fact->key, '.name') && is_string($fact->value) && trim($fact->value) !== '') {
                return true;
            }
            if ($fact->key === 'result_status' && $fact->value === 'available') {
                return true;
            }
        }

        return false;
    }

    private function normalizeAmount(string $raw): ?string
    {
        $clean = str_replace([',', ' '], ['', ''], trim($raw));
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
