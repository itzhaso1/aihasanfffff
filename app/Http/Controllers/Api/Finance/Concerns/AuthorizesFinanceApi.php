<?php

namespace App\Http\Controllers\Api\Finance\Concerns;

use App\Exceptions\Api\ApiErrorCode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\FinanceAccess;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait AuthorizesFinanceApi
{
    protected function requireWorkspace(WorkspaceContext $workspaceContext): Workspace
    {
        $workspace = $workspaceContext->workspace();
        if (! $workspace) {
            throw new HttpResponseException($this->fail(
                'Workspace context is required.',
                ApiErrorCode::Forbidden,
                403,
            ));
        }

        return $workspace;
    }

    protected function authorizeFinanceApi(Request $request, Workspace $workspace, string $permission): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail(
                'Unauthenticated.',
                ApiErrorCode::Unauthorized,
                401,
            ));
        }

        if (! $this->financeAccess()->can($user, $workspace, $permission)) {
            throw new HttpResponseException($this->fail(
                'You are not allowed to access this financial resource.',
                ApiErrorCode::Forbidden,
                403,
            ));
        }

        return $user;
    }

    protected function authorizeAssistantMember(Request $request, Workspace $workspace): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail(
                'Unauthenticated.',
                ApiErrorCode::Unauthorized,
                401,
            ));
        }

        $workspaceAccess = app(WorkspaceAccess::class);
        if (! $workspaceAccess->isActiveMember($user, $workspace)
            && ! $workspaceAccess->isWorkspaceOwner($user, $workspace)) {
            throw new HttpResponseException($this->fail(
                'You are not allowed to access this financial resource.',
                ApiErrorCode::Forbidden,
                403,
            ));
        }

        return $user;
    }

    /**
     * Owner/admin are finance administrators. Manager is operational only.
     */
    protected function isElevatedFinanceMember(Workspace $workspace, User $user): bool
    {
        return $this->financeAccess()->isFinanceAdministrator($user, $workspace);
    }

    /**
     * Matches financePermissionMap['accounting.view'] so invoice journals
     * stay visible to finance administrators and accountants, not managers.
     */
    protected function mayViewAccounting(?User $user, Workspace $workspace): bool
    {
        if (! $user) {
            return false;
        }

        return $this->financeAccess()->can($user, $workspace, 'accounting.view');
    }

    /**
     * UI gating map. Laravel still authorizes every mutation.
     *
     * @return array<string, bool>
     */
    protected function financePermissionMap(User $user, Workspace $workspace): array
    {
        return $this->financeAccess()->permissionMap($user, $workspace);
    }

    protected function financeAccess(): FinanceAccess
    {
        return app(FinanceAccess::class);
    }
}
