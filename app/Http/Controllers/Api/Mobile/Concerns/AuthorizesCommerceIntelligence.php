<?php

namespace App\Http\Controllers\Api\Mobile\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Commerce\CommerceIntelligenceAccess;
use Illuminate\Http\Request;

trait AuthorizesCommerceIntelligence
{
    protected function authorizeCommerceIntelligenceView(Request $request, Workspace $workspace): User
    {
        $user = $request->user();
        abort_unless($user && app(CommerceIntelligenceAccess::class)->canViewIntelligence($user, $workspace), 403);

        return $user;
    }

    protected function authorizeCommerceIntelligenceManage(Request $request, Workspace $workspace): User
    {
        $user = $request->user();
        abort_unless($user && app(CommerceIntelligenceAccess::class)->canManageIntelligence($user, $workspace), 403);

        return $user;
    }

    protected function authorizeCommerceIntelligenceEvaluate(Request $request, Workspace $workspace): User
    {
        $user = $request->user();
        abort_unless($user && app(CommerceIntelligenceAccess::class)->canEvaluateIntelligence($user, $workspace), 403);

        return $user;
    }
}
