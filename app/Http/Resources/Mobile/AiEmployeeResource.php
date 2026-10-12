<?php

namespace App\Http\Resources\Mobile;

use App\AI\Employees\Models\AiEmployee;
use App\AI\Models\ModelSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiEmployee */
class AiEmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $persona = $this->persona;
        $memory = $this->memorySettings;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'avatar_key' => $this->avatar_key,
            'gender' => $this->gender,
            'role' => $this->role,
            'status' => $this->status->value,
            'is_default' => $this->isDefault(),
            'instructions' => $this->instructions,
            'training_profile' => $this->training_profile,
            'experience_notes' => $this->experience_notes,
            'preset_key' => $this->preset_key,
            'preset_version' => $this->preset_version,
            'config_version' => $this->config_version,
            'persona' => $persona === null ? null : [
                'language' => $persona->language,
                'dialect' => $persona->dialect,
                'formality' => $persona->formality,
                'tone' => $persona->tone,
                'emoji_policy' => $persona->emoji_policy,
                'greeting_policy' => $persona->greeting_policy,
                'traits' => $persona->traits ?? [],
                'style_notes' => $persona->style_notes,
            ],
            'skills' => $this->skills->map(fn ($skill): array => ['key' => $skill->skill_key, 'settings' => $skill->settings ?? (object) []])->values(),
            'tools' => $this->toolPermissions->map(fn ($tool): array => [
                'name' => $tool->tool_name,
                'allowed' => (bool) $tool->allowed,
                'max_calls_per_run' => $tool->max_calls_per_run,
            ])->values(),
            'memory' => $memory === null ? null : [
                'conversation_memory_enabled' => (bool) $memory->conversation_memory_enabled,
                'customer_memory_enabled' => (bool) $memory->customer_memory_enabled,
                'commerce_context_enabled' => (bool) $memory->commerce_context_enabled,
                'relevant_history_enabled' => (bool) $memory->relevant_history_enabled,
            ],
            'model' => $this->effectiveModel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Canonical employee model settings plus the resolved fallback when none are stored.
     *
     * @return array<string, mixed>
     */
    private function effectiveModel(): array
    {
        $resolved = app(ModelSettingsResolver::class)->resolve((int) $this->id);
        $stored = $this->modelSettings;

        return [
            'provider' => $resolved->provider,
            'model' => $resolved->model,
            'temperature' => $resolved->temperature,
            'response_length' => $stored?->response_length?->value ?? 'short',
            'max_output_tokens' => $resolved->maxOutputTokens,
            'source' => 'platform',
            'is_override' => false,
        ];
    }
}
