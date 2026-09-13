<?php

namespace App\Http\Controllers\Api\Mobile\Concerns;

use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;

trait ResolvesMobileWorkspace
{
    protected function requireWorkspace(WorkspaceContext $workspaceContext): Workspace
    {
        $workspace = $workspaceContext->workspace();

        if (! $workspace) {
            throw new HttpResponseException($this->fail('مساحة العمل غير محددة.', 422));
        }

        return $workspace;
    }
}
