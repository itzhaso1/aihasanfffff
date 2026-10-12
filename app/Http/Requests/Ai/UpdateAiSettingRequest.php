<?php

namespace App\Http\Requests\Ai;

use App\Services\AI\AssistantName;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Chat\ChatAiRole;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->canManageAiSettings($user, $workspace);
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('assistant_name') && ! $this->exists('name')) {
            $this->merge(['name' => $this->input('assistant_name')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assistant_name' => ['sometimes', 'nullable', 'string', 'max:'.AssistantName::MAX_LENGTH],
            'ai_role' => ['nullable', 'string', Rule::in(ChatAiRole::all())],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:100'],
            'reply_style' => ['nullable', 'string', 'max:100'],
            'rules' => ['nullable', 'array'],
            'business_information' => ['nullable', 'array'],
            'agent_persona' => ['nullable', 'array'],
            'provider' => ['prohibited'],
            'model' => ['prohibited'],
            'max_tokens' => ['sometimes', 'required', 'integer', 'min:50', 'max:1024'],
            'temperature' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
