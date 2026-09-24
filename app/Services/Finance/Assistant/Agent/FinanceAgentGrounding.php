<?php

namespace App\Services\Finance\Assistant\Agent;

use App\Support\Money\Money;

final class FinanceAgentGrounding
{
    /**
     * @param  list<array<string, mixed>>  $minimizedResults
     */
    public function answerIsGrounded(string $answer, array $minimizedResults): bool
    {
        $allowed = $this->numbersFrom($minimizedResults);
        foreach ($this->numbersFromAnswer($answer) as $number) {
            if (! $this->contains($allowed, $number)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $minimizedResults
     */
    public function fallbackAnswer(array $minimizedResults, string $locale): string
    {
        $lines = [];
        foreach ($minimizedResults as $result) {
            if (($result['available'] ?? false) !== true) {
                continue;
            }
            if (isset($result['value'])) {
                $lines[] = ($result['metric'] ?? 'metric').': '.$result['value'].' '.($result['currency'] ?? '');
            }
            if (isset($result['net_profit'])) {
                $lines[] = 'net_profit: '.$result['net_profit'].' '.($result['currency'] ?? '');
            }
            if (isset($result['revenue'], $result['expenses'])) {
                $lines[] = 'revenue: '.$result['revenue'].' · expenses: '.$result['expenses'];
            }
        }

        if ($lines === []) {
            return $locale === 'ar'
                ? 'لا أستطيع تأكيد هذا الرقم من السجلات المتاحة.'
                : 'I cannot confirm this figure from the available records.';
        }

        $header = $locale === 'ar'
            ? 'الأرقام التالية من السجلات المتاحة:'
            : 'The following figures are from available records:';

        return $header."\n".implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function numbersFrom(mixed $payload): array
    {
        $found = [];
        $this->walk($payload, $found);

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $found
     */
    private function walk(mixed $payload, array &$found): void
    {
        if (is_int($payload) || is_float($payload)) {
            $found[] = $this->normalize((string) $payload);

            return;
        }
        if (is_string($payload)) {
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $payload) === 1) {
                $found[] = $this->normalize($payload);
            }
            foreach ($this->numbersFromAnswer($payload) as $number) {
                $found[] = $number;
            }

            return;
        }
        if (is_array($payload)) {
            foreach ($payload as $value) {
                $this->walk($value, $found);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function numbersFromAnswer(string $answer): array
    {
        $converted = strtr($answer, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $withoutDates = preg_replace('/\d{4}-\d{2}-\d{2}/', ' ', $converted) ?? $converted;
        preg_match_all('/-?\d{1,3}(?:,\d{3})+(?:\.\d+)?|-?\d+\.\d+|-?\d{3,}/', $withoutDates, $matches);

        return array_map(fn (string $value): string => $this->normalize($value), $matches[0] ?? []);
    }

    private function normalize(string $value): string
    {
        $clean = str_replace([',', ' ', '٬'], '', trim($value));
        $clean = str_replace(['٪', '%'], '', $clean);
        if ($clean === '' || ! is_numeric($clean)) {
            return $clean;
        }

        return Money::of($clean);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function contains(array $allowed, string $number): bool
    {
        foreach ($allowed as $candidate) {
            if ($candidate === $number) {
                return true;
            }
        }

        return false;
    }
}
