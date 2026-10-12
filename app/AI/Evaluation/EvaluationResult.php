<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

use App\AI\Core\Support\TypedList;

final readonly class EvaluationResult
{
    /** @var list<EvaluationFinding> */
    public array $findings;

    /**
     * @param  list<EvaluationFinding>  $findings
     */
    public function __construct(
        public string $evaluator,
        public string $version,
        public EvaluationStatus $status,
        public int $score,
        public string $summary,
        array $findings = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $evaluator) !== 1) {
            throw new \InvalidArgumentException('Evaluator id must be a snake_case identifier.');
        }
        if (trim($version) === '') {
            throw new \InvalidArgumentException('Evaluator version cannot be empty.');
        }
        if ($score < 0 || $score > 100) {
            throw new \InvalidArgumentException('Evaluation score must be 0-100.');
        }
        $this->findings = TypedList::of($findings, EvaluationFinding::class, 'Evaluation findings');
    }

    /**
     * @param  list<EvaluationFinding>  $findings
     */
    public static function fromFindings(string $evaluator, string $version, array $findings): self
    {
        $findings = self::sort(self::unique($findings));
        $fails = 0;
        $warnings = 0;
        foreach ($findings as $finding) {
            if ($finding->severity === EvaluationSeverity::Fail) {
                $fails++;
            } else {
                $warnings++;
            }
        }

        $status = match (true) {
            $fails > 0 => EvaluationStatus::Failed,
            $warnings > 0 => EvaluationStatus::PassedWithWarnings,
            default => EvaluationStatus::Passed,
        };
        $score = max(0, 100 - (25 * $fails) - (5 * $warnings));

        return new self(
            $evaluator,
            $version,
            $status,
            $score,
            $fails.' fail, '.$warnings.' warn',
            $findings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'evaluator' => $this->evaluator,
            'version' => $this->version,
            'status' => $this->status->value,
            'score' => $this->score,
            'summary' => $this->summary,
            'findings' => array_map(static fn (EvaluationFinding $finding): array => $finding->toArray(), $this->findings),
        ];
    }

    /**
     * @param  list<EvaluationFinding>  $findings
     * @return list<EvaluationFinding>
     */
    private static function unique(array $findings): array
    {
        $unique = [];
        $seen = [];
        foreach ($findings as $finding) {
            $key = $finding->rule.':'.$finding->code;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }

    /**
     * @param  list<EvaluationFinding>  $findings
     * @return list<EvaluationFinding>
     */
    private static function sort(array $findings): array
    {
        usort(
            $findings,
            static fn (EvaluationFinding $a, EvaluationFinding $b): int => [$a->category->value, $a->code, $a->rule]
                <=> [$b->category->value, $b->code, $b->rule],
        );

        return $findings;
    }
}
