<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->canManageMembers($user, $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => [
                'required',
                Rule::in([
                    WorkspaceAccess::ROLE_ADMIN,
                    WorkspaceAccess::ROLE_MANAGER,
                    WorkspaceAccess::ROLE_AGENT,
                    WorkspaceAccess::ROLE_ACCOUNTANT,
                    WorkspaceAccess::ROLE_RECEPTIONIST,
                    WorkspaceAccess::ROLE_STAFF_DOCTOR,
                    WorkspaceAccess::ROLE_STAFF,
                ]),
            ],
        ];
    }
}
