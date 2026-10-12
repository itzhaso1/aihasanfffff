<?php

declare(strict_types=1);

namespace App\AI\Models;

final readonly class ResolvedModelSettings
{
    public function __construct(
        public int $employeeId,
        public int $workspaceId,
        public string $provider,
        public string $model,
        public float $temperature,
        public int $maxOutputTokens,
        public ?int $thinkingBudget = null,
    ) {
        if ($employeeId <= 0 || $workspaceId <= 0) {
            throw ModelGatewayException::settingsNotFound($employeeId);
        }
        if (trim($provider) === '' || trim($model) === '') {
            throw ModelGatewayException::providerNotAllowed($provider);
        }
        if ($maxOutputTokens < 1) {
            throw ModelGatewayException::modelNotAllowed($provider, $model);
        }
    }
}
