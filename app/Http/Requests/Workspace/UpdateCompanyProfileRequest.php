<?php

namespace App\Http\Requests\Workspace;

use App\Models\WorkspaceCompanyProfile;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->canManageAiSettings($user, $workspace);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->expectsJson()) {
            return parent::getRedirectUrl();
        }

        return route('workspace.ai-settings.edit', ['tab' => 'company']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:'.WorkspaceCompanyProfile::NAME_MAX],
            'about' => ['nullable', 'string', 'max:'.WorkspaceCompanyProfile::ABOUT_MAX],
            'offerings' => ['nullable', 'string', 'max:'.WorkspaceCompanyProfile::OFFERINGS_MAX],
            'services' => ['nullable', 'string', 'max:'.WorkspaceCompanyProfile::SERVICES_MAX],
            'audience' => ['nullable', 'string', 'max:'.WorkspaceCompanyProfile::AUDIENCE_MAX],
            'reply_guidelines' => ['nullable', 'string', 'max:'.WorkspaceCompanyProfile::REPLY_GUIDELINES_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'اسم الشركة',
            'about' => 'نبذة عن الشركة',
            'offerings' => 'ماذا نبيع',
            'services' => 'الخدمات التي نقدمها',
            'audience' => 'عملاؤنا',
            'reply_guidelines' => 'معلومات إضافية وتعليمات للرد',
        ];
    }
}
