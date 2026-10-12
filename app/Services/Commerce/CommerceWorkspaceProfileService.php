<?php

namespace App\Services\Commerce;

use App\Enums\Commerce\CommerceOperatingMode;
use App\Models\Commerce\CommerceWorkspaceProfile;
use App\Models\Workspace;

final class CommerceWorkspaceProfileService
{
    public function __construct(
        private readonly ChatCommerceTaxRateResolver $taxRateResolver,
    ) {}

    public function operatingMode(Workspace $workspace): CommerceOperatingMode
    {
        $profile = CommerceWorkspaceProfile::query()
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($profile !== null) {
            return $profile->operating_mode;
        }

        $default = (string) config('commerce.default_operating_mode', CommerceOperatingMode::IndependentStore->value);

        return CommerceOperatingMode::tryFrom($default) ?? CommerceOperatingMode::IndependentStore;
    }

    public function ensureProfile(Workspace $workspace, ?CommerceOperatingMode $mode = null): CommerceWorkspaceProfile
    {
        return CommerceWorkspaceProfile::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'operating_mode' => $mode ?? CommerceOperatingMode::IndependentStore,
            ],
        );
    }

    public function defaultTaxRate(Workspace $workspace): string
    {
        return $this->taxRateResolver->defaultProfileFixed((int) $workspace->id)['rate'];
    }

    public function updateDefaultTaxRate(Workspace $workspace, string $rate): CommerceWorkspaceProfile
    {
        $profile = $this->ensureProfile($workspace);
        $metadata = is_array($profile->metadata) ? $profile->metadata : [];
        $metadata['default_tax_rate'] = number_format((float) $rate, 2, '.', '');
        $profile->update(['metadata' => $metadata]);

        return $profile->fresh() ?? $profile;
    }
}
