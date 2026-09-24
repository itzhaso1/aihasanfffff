<?php

namespace App\Services\Commerce;

use App\Enums\Commerce\CommerceOperatingMode;
use App\Models\Commerce\CommerceWorkspaceProfile;
use App\Models\Workspace;

final class CommerceWorkspaceProfileService
{
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
}
