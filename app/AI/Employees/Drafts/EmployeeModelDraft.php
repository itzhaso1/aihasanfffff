<?php

declare(strict_types=1);

namespace App\AI\Employees\Drafts;

use App\AI\Employees\EmployeeResponseLength;
use App\AI\Employees\InvalidEmployeeData;

/**
 * Employee generation settings. Provider and model are always the Platform Admin
 * selection; only temperature, length, and token caps belong to the employee.
 */
final readonly class EmployeeModelDraft
{
    public const MIN_OUTPUT_TOKENS = 64;

    public const MAX_OUTPUT_TOKENS = 1024;

    public const MAX_THINKING_BUDGET = 24_576;

    public function __construct(
        public string $provider,
        public string $model,
        public float $temperature = 0.4,
        public EmployeeResponseLength $responseLength = EmployeeResponseLength::Short,
        public ?int $maxOutputTokens = null,
        public ?int $thinkingBudget = null,
    ) {
        if (trim($provider) === '' || trim($model) === '') {
            throw InvalidEmployeeData::field('model', 'Provider and model are required.');
        }
        if ($temperature < 0 || $temperature > 1) {
            throw InvalidEmployeeData::field('model.temperature', 'Temperature must be between 0 and 1.');
        }
        if ($maxOutputTokens !== null && ($maxOutputTokens < self::MIN_OUTPUT_TOKENS || $maxOutputTokens > self::MAX_OUTPUT_TOKENS)) {
            throw InvalidEmployeeData::field('model.max_output_tokens', 'Output tokens are out of range.');
        }
        if ($thinkingBudget !== null && ($thinkingBudget < 0 || $thinkingBudget > self::MAX_THINKING_BUDGET)) {
            throw InvalidEmployeeData::field('model.thinking_budget', 'Thinking budget is out of range.');
        }
    }
}
