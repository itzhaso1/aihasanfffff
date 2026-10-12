<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Employees\Drafts\EmployeeModelDraft;
use App\Services\AI\AiSafety;

/**
 * Applies platform-selected provider/model to employee drafts. Temperature and
 * response length stay on the employee; the runtime model never does.
 */
final class EmployeeModelPolicy
{
    private const DEFAULT_TEMPERATURE = 0.4;

    public function __construct(private readonly AiSafety $safety) {}

    public function validate(EmployeeModelDraft $draft): EmployeeModelDraft
    {
        $platform = $this->safety->selected();

        return new EmployeeModelDraft(
            provider: $platform->provider,
            model: $platform->model,
            temperature: $this->safety->capTemperature($draft->temperature),
            responseLength: $draft->responseLength,
            maxOutputTokens: $draft->maxOutputTokens,
            thinkingBudget: $draft->thinkingBudget,
        );
    }

    public function defaults(): EmployeeModelDraft
    {
        $platform = $this->safety->selected();

        return new EmployeeModelDraft(
            provider: $platform->provider,
            model: $platform->model,
            temperature: self::DEFAULT_TEMPERATURE,
        );
    }

    /** Legacy values keep temperature; provider and model always come from Platform Admin. */
    public function fromLegacy(?string $provider, ?string $model, ?float $temperature, EmployeeResponseLength $length, ?int $maxOutputTokens): EmployeeModelDraft
    {
        unset($provider, $model);
        $platform = $this->safety->selected();

        return new EmployeeModelDraft(
            provider: $platform->provider,
            model: $platform->model,
            temperature: $this->safety->capTemperature($temperature ?? self::DEFAULT_TEMPERATURE),
            responseLength: $length,
            maxOutputTokens: $maxOutputTokens,
        );
    }
}
