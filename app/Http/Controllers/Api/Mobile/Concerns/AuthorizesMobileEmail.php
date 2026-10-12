<?php

namespace App\Http\Controllers\Api\Mobile\Concerns;

use App\Services\Worker\WorkerEngagementAuthorization;
use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait AuthorizesMobileEmail
{
    protected function authorizeEmailView(Request $request): void
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();

        if (
            $user
            && (
                app(WorkspaceAccess::class)->canUseEmail($user, $workspace)
                || app(WorkerEngagementAuthorization::class)->workerCanInWorkspace(
                    $user,
                    $workspace,
                    WorkerEngagementAuthorization::EMAIL_VIEW_CAPABILITY,
                )
            )
        ) {
            return;
        }

        throw new HttpResponseException($this->fail('ليس لديك صلاحيات الوصول إلى البريد.', 403));
    }

    protected function authorizeEmailUse(Request $request): void
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! app(WorkspaceAccess::class)->canUseEmail($user, $workspace)) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية البريد.', 403));
        }
    }
}
