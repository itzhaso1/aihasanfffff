<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

class WorkerEngagementActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        $user = $this->user();

        return $workspace !== null
            && $user !== null
            && app(WorkspaceAccess::class)->canManageMembers($user, $workspace);
    }

    public function rules(): array
    {
        return [];
    }
}
