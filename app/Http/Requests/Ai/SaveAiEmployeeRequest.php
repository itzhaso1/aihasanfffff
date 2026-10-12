<?php

namespace App\Http\Requests\Ai;

use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for employee create/update payloads. Allowed values (roles, skills,
 * dialects, models) are checked by the employee draft so they have a single source.
 */
class SaveAiEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->canManageAiSettings($user, $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset_key' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:64'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:128'],
            'avatar_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:16'],
            'role' => ['sometimes', 'string', 'max:64'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'training_profile' => ['sometimes', 'nullable', 'string', 'max:64'],
            'experience_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'persona' => ['sometimes', 'array'],
            'persona.language' => ['sometimes', 'string', 'max:16'],
            'persona.dialect' => ['sometimes', 'nullable', 'string', 'max:32'],
            'persona.formality' => ['sometimes', 'string', 'max:32'],
            'persona.tone' => ['sometimes', 'string', 'max:32'],
            'persona.emoji_policy' => ['sometimes', 'string', 'max:16'],
            'persona.greeting_policy' => ['sometimes', 'string', 'max:32'],
            'persona.traits' => ['sometimes', 'array', 'max:8'],
            'persona.traits.*' => ['string', 'max:32'],
            'persona.style_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'skills' => ['sometimes', 'array', 'max:20'],
            'tools' => ['sometimes', 'array', 'max:30'],
            'memory' => ['sometimes', 'array'],
            'memory.*' => ['boolean'],
            'model' => ['sometimes', 'array'],
            'model.provider' => ['prohibited'],
            'model.model' => ['prohibited'],
            'model.temperature' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'model.response_length' => ['sometimes', 'string', 'max:16'],
            'model.max_output_tokens' => ['sometimes', 'nullable', 'integer', 'min:64', 'max:1024'],
            'model.thinking_budget' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:24576'],
        ];
    }

    /**
     * Employee fields only, in the draft array shape.
     *
     * @return array<string, mixed>
     */
    public function employeeFields(): array
    {
        return collect($this->validated())->except(['preset_key'])->all();
    }
}
