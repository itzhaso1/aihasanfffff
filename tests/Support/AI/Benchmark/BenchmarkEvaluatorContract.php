<?php

declare(strict_types=1);

namespace Tests\Support\AI\Benchmark;

/**
 * Classifies hand-built fixtures only. It does not score a live model.
 */
final class BenchmarkEvaluatorContract
{
    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function classify(array $fixture): array
    {
        $actual = (string) ($fixture['actual'] ?? '');
        $issues = [];
        if (($fixture['expect_intent'] ?? null) !== null && ! str_contains($actual, (string) $fixture['expect_intent'])) {
            $issues[] = 'wrong_intent';
        }
        if (($fixture['expect_domain'] ?? null) !== null && ! str_contains($actual, (string) $fixture['expect_domain'])) {
            $issues[] = 'wrong_domain';
        }
        if (($fixture['fabricated'] ?? false) === true) {
            $issues[] = 'fabricated_fact';
        }
        if (($fixture['unnecessary_clarification'] ?? false) === true) {
            $issues[] = 'unnecessary_clarification';
        }
        if (($fixture['decision'] ?? null) === 'mismatch') {
            $issues[] = 'wrong_decision_behavior';
        }
        if (($fixture['style'] ?? null) === 'mismatch') {
            $issues[] = 'saudi_style_mismatch';
        }

        return [
            'id' => $fixture['id'],
            'status' => $issues === [] ? 'PASS' : 'FAIL',
            'issues' => $issues,
        ];
    }
}
