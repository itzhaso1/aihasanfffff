<?php

declare(strict_types=1);

namespace App\AI\Models;

use App\AI\Employees\EmployeeModelPolicy;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Models\AiEmployeeModelSettings;
use App\Services\AI\AiSafety;

final class ModelSettingsResolver
{
    public function __construct(
        private readonly EmployeeModelPolicy $policy,
        private readonly AiSafety $safety,
    ) {}

    public function resolve(int $employeeId): ResolvedModelSettings
    {
        $employee = AiEmployee::query()
            ->withoutGlobalScope('workspace')
            ->whereKey($employeeId)
            ->first();
        if (! $employee instanceof AiEmployee) {
            throw ModelGatewayException::settingsNotFound($employeeId);
        }

        $row = AiEmployeeModelSettings::query()
            ->withoutGlobalScope('workspace')
            ->where('workspace_id', $employee->workspace_id)
            ->where('ai_employee_id', $employee->id)
            ->first();

        $platform = $this->safety->selected();
        $provider = ProviderId::normalize($platform->provider);
        $model = $platform->model;

        if ($row instanceof AiEmployeeModelSettings) {
            $temperature = (float) $row->temperature;
            $maxTokens = $row->effectiveMaxOutputTokens();
            $thinking = $row->thinking_budget;
        } else {
            $draft = $this->policy->defaults();
            $temperature = $draft->temperature;
            $maxTokens = $draft->maxOutputTokens ?? $draft->responseLength->defaultMaxOutputTokens();
            $thinking = $draft->thinkingBudget;
        }

        if (! in_array($provider, $this->safety->allowedProviders(), true)) {
            throw ModelGatewayException::providerNotAllowed($provider);
        }
        $allowedModels = $this->safety->allowedModels($provider);
        if ($allowedModels !== [] && ! in_array($model, $allowedModels, true)) {
            throw ModelGatewayException::modelNotAllowed($provider, $model);
        }

        if ($thinking === null && $provider === ProviderId::GEMINI) {
            $configured = (int) config('ai.google_ai_studio.thinking_budget', 0);
            $thinking = $configured >= 0 ? $configured : null;
        }

        return new ResolvedModelSettings(
            employeeId: (int) $employee->id,
            workspaceId: (int) $employee->workspace_id,
            provider: $provider,
            model: $model,
            temperature: $this->safety->capTemperature($temperature),
            maxOutputTokens: $this->safety->capMaxTokens($maxTokens),
            thinkingBudget: $thinking,
        );
    }
}
