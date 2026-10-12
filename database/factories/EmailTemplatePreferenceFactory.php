<?php

namespace Database\Factories;

use App\Models\EmailTemplatePreference;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplatePreference>
 */
class EmailTemplatePreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'template_key' => 'construction_welcome',
            'settings' => [],
        ];
    }
}
