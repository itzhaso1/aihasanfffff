<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\User;
use App\Support\Authorization\FinanceAccess;
use Illuminate\Http\Request;

abstract class FinanceBaseController extends Controller
{
    use InteractsWithWorkspace;

    protected function authorizeFinance(Request $request, string $permission = 'finance.view'): void
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless(
            app(FinanceAccess::class)->can($user, $this->currentWorkspace(), $permission),
            403,
            'You are not allowed to access financial module.'
        );
    }
}
