<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

final readonly class EvaluationFinding
{
    public function __construct(
        public string $rule,
        public EvaluationCategory $category,
        public EvaluationSeverity $severity,
        public string $code,
    ) {
        if (trim($rule) === '' || trim($code) === '') {
            throw new \InvalidArgumentException('Evaluation findings need a rule and a code.');
        }
    }

    /**
     * @return array{rule: string, category: string, severity: string, code: string}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'code' => $this->code,
        ];
    }
}
