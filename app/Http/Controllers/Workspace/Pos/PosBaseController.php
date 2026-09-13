<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\User;
use Illuminate\Http\Request;

abstract class PosBaseController extends Controller
{
    use InteractsWithWorkspace;

    protected function authorizePos(Request $request, string $permission = 'orders.manage'): void
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->can($permission) || $user->can('workspace.manage')) {
            return;
        }

        $workspace = $this->currentWorkspace();
        $isElevatedMember = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager', 'agent', 'receptionist'])
            ->exists();

        abort_unless($isElevatedMember, 403, 'ليس لديك صلاحية للوصول إلى وحدة الكاشير.');
    }

    /**
     * Web is management + QR only. Opening/closing sessions, checkout,
     * kitchen status, and cart checkout belong to the Flutter cashier.
     */
    protected function abortWebPosOperation(): never
    {
        abort(403, 'تشغيل الجلسات والدفع يتم من تطبيق الكاشير فقط. هذه الواجهة للإدارة وQR Menu.');
    }
}
