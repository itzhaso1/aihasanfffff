<?php

namespace App\Services\Finance\Assistant\Concerns;

use App\Models\Workspace;

trait BuildsAssistantFacts
{
    protected function workspaceCurrency(Workspace $workspace): string
    {
        $currency = data_get($workspace->settings ?? [], 'finance.currency')
            ?? data_get($workspace->settings ?? [], 'pos.currency');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'SAR';
    }

    /**
     * @return array<string, mixed>
     */
    protected function factEnvelope(
        Workspace $workspace,
        string $source,
        string $from,
        string $to,
        array $payload,
    ): array {
        return array_merge([
            'currency' => $this->workspaceCurrency($workspace),
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'source' => $source,
            'computed_at' => now()->toIso8601String(),
        ], $payload);
    }
}
