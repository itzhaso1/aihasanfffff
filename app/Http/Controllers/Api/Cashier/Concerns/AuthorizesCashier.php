<?php

namespace App\Http\Controllers\Api\Cashier\Concerns;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait AuthorizesCashier
{
    protected function authorizeCashier(Request $request, Workspace $workspace, string $permission = 'orders.manage'): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }

        // Align with Web PosBaseController::authorizePos — do NOT grant via pos.manage alone.
        if ($user->can($permission) || $user->can('workspace.manage')) {
            return $user;
        }

        $isElevatedMember = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager', 'agent', 'receptionist'])
            ->exists();

        if (! $isElevatedMember) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية تنفيذ هذه العملية.', 403));
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    protected function permissionMap(User $user, Workspace $workspace): array
    {
        $elevated = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager', 'agent', 'receptionist'])
            ->exists();

        // Same truth source as Web: Spatie permission OR workspace.manage OR elevated membership.
        $can = fn (string $permission): bool => $user->can($permission)
            || $user->can('workspace.manage')
            || $elevated;

        return [
            'pos.use' => $can('orders.manage'),
            'orders.manage' => $can('orders.manage'),
            'orders.create' => $can('orders.manage'),
            'orders.refund' => $can('orders.manage'),
            'orders.discount' => $can('orders.manage'),
            'tables.manage' => $can('tables.manage'),
            'reports.view' => $can('reports.view'),
            'menu.manage' => $can('menu.manage'),
            'pos.manage' => $can('pos.manage'),
            // Shifts are not implemented in Laravel yet.
            'shifts.manage' => false,
        ];
    }
}
