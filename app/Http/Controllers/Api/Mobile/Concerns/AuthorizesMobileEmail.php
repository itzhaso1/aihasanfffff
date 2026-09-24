<?php

namespace App\Http\Controllers\Api\Mobile\Concerns;

use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait AuthorizesMobileEmail
{
    protected function authorizeEmailUse(Request $request): void
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! app(WorkspaceAccess::class)->canUseEmail($user, $workspace)) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية البريد.', 403));
        }
    }
}
