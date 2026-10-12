<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Models\Worker\WorkerProfile;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkerDiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->isActiveMember($user, $workspace);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'country_code' => [
                'nullable',
                'string',
                Rule::in(WorkerProfile::COUNTRIES),
            ],
            'availability_status' => [
                'nullable',
                'string',
                Rule::in([
                    WorkerProfile::AVAILABILITY_AVAILABLE,
                    WorkerProfile::AVAILABILITY_LIMITED,
                ]),
            ],
            'sales_experience_level' => ['nullable', 'string', 'max:32'],
            'skill_key' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
        ];
    }
}
