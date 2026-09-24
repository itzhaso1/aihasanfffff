<?php

namespace Database\Factories\Commerce;

use App\Enums\Commerce\CommerceOperatingMode;
use App\Models\Commerce\CommerceWorkspaceProfile;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommerceWorkspaceProfile> */
class CommerceWorkspaceProfileFactory extends Factory
{
    protected $model = CommerceWorkspaceProfile::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'operating_mode' => CommerceOperatingMode::IndependentStore,
            'metadata' => null,
        ];
    }
}
