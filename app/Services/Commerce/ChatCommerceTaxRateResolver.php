<?php

namespace App\Services\Commerce;

use App\Models\Commerce\CommerceWorkspaceProfile;

/**
 * Chat-owned tax profile for Commerce lines.
 *
 * Workspace override lives on commerce_workspace_profiles.metadata.default_tax_rate.
 * Fallback is config('commerce.default_tax_rate'). Finance tables are never read.
 */
final class ChatCommerceTaxRateResolver
{
    /**
     * @return array{type:string, rate:string}
     */
    public function defaultProfileFixed(int $workspaceId): array
    {
        $profile = CommerceWorkspaceProfile::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        $rate = data_get($profile?->metadata, 'default_tax_rate');
        if ($rate !== null && $rate !== '') {
            return [
                'type' => 'standard',
                'rate' => $this->fixedRate($rate),
            ];
        }

        return [
            'type' => 'standard',
            'rate' => $this->fixedRate(config('commerce.default_tax_rate', '15.00')),
        ];
    }

    private function fixedRate(mixed $rate): string
    {
        return number_format((float) $rate, 2, '.', '');
    }
}
