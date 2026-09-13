<?php

namespace App\Services\Finance\Tax;

use App\Enums\Finance\TaxProfileType;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceTaxRate;

/**
 * Deterministic workspace tax-rate resolution.
 *
 * Priority for a standard line:
 * 1. Explicit line tax rate when the caller provided one
 * 2. Document/header default rate when the caller provided one
 * 3. Active default finance_tax_rates row for the workspace
 * 4. finance_settings.default_vat_rate
 * 5. TaxCalculationService::FALLBACK_STANDARD_RATE (technical safety only)
 *
 * Non-standard classifications always resolve to 0. This is not a legal
 * rate catalogue and is not a ZATCA integration.
 */
class TaxRateResolver
{
    /**
     * @return array{type:string, rate:float}
     */
    public function defaultProfile(int $workspaceId): array
    {
        $defaultTaxRate = FinanceTaxRate::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($defaultTaxRate) {
            return [
                'type' => TaxProfileType::tryFrom((string) $defaultTaxRate->type)?->value
                    ?? TaxProfileType::Standard->value,
                'rate' => (float) $defaultTaxRate->rate,
            ];
        }

        $settings = FinanceSetting::forWorkspaceId($workspaceId);

        return [
            'type' => TaxProfileType::Standard->value,
            'rate' => (float) ($settings?->default_vat_rate ?? TaxCalculationService::FALLBACK_STANDARD_RATE),
        ];
    }

    public function hasExplicitRate(mixed $rate): bool
    {
        return $rate !== null && $rate !== '';
    }

    public function resolveStandardRate(int $workspaceId, mixed $explicitRate, mixed $documentDefaultRate = null): float
    {
        if ($this->hasExplicitRate($explicitRate)) {
            return $this->assertValidRate((float) $explicitRate);
        }

        if ($this->hasExplicitRate($documentDefaultRate)) {
            return $this->assertValidRate((float) $documentDefaultRate);
        }

        return $this->assertValidRate((float) $this->defaultProfile($workspaceId)['rate']);
    }

    public function assertValidRate(float $rate): float
    {
        if ($rate < 0 || $rate > 100) {
            throw new TaxCalculationException('نسبة الضريبة يجب أن تكون بين 0 و 100.');
        }

        return $rate;
    }
}
