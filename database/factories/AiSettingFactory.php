<?php

namespace Database\Factories;

use App\Models\AiSetting;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSetting>
 */
class AiSettingFactory extends Factory
{
    protected $model = AiSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'AI Assistant',
            'instructions' => 'Be helpful and concise.',
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'max_tokens' => 512,
            'temperature' => 0.4,
            'is_active' => true,
        ];
    }
}
