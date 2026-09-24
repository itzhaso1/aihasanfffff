<?php

namespace App\Http\Requests\Ai;

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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ai_role' => ['nullable', 'string', Rule::in(ChatAiRole::all())],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:100'],
            'reply_style' => ['nullable', 'string', 'max:100'],
            'rules' => ['nullable', 'array'],
            'business_information' => ['nullable', 'array'],
            'agent_persona' => ['nullable', 'array'],
            'provider' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'max_tokens' => ['sometimes', 'required', 'integer', 'min:50', 'max:1024'],
            'temperature' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
