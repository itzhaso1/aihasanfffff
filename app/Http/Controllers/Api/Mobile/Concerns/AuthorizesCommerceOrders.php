<?php

namespace App\Http\Controllers\Api\Mobile\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Commerce\CommerceOrderAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait AuthorizesCommerceOrders
{
    protected function authorizeCommerceCartView(Request $request, Workspace $workspace): User
    {
        return $this->authorizeCommerce(
            $request,
            $workspace,
            fn (CommerceOrderAccess $access, User $user): bool => $access->canViewCarts($user, $workspace),
            'لا تملك صلاحية عرض سلات التجارة.',
        );
    }

    protected function authorizeCommerceCartManage(Request $request, Workspace $workspace): User
    {
        return $this->authorizeCommerce(
            $request,
            $workspace,
            fn (CommerceOrderAccess $access, User $user): bool => $access->canManageCarts($user, $workspace),
            'لا تملك صلاحية إدارة سلات التجارة.',
        );
    }

    protected function authorizeCommerceOrderView(Request $request, Workspace $workspace): User
    {
        return $this->authorizeCommerce(
            $request,
            $workspace,
            fn (CommerceOrderAccess $access, User $user): bool => $access->canViewOrders($user, $workspace),
            'لا تملك صلاحية عرض طلبات التجارة.',
        );
    }

    protected function authorizeCommerceOrderManage(Request $request, Workspace $workspace): User
    {
        return $this->authorizeCommerce(
            $request,
            $workspace,
            fn (CommerceOrderAccess $access, User $user): bool => $access->canManageOrders($user, $workspace),
            'لا تملك صلاحية إدارة طلبات التجارة.',
        );
    }

    protected function authorizeCommerceOrderCancel(Request $request, Workspace $workspace): User
    {
        return $this->authorizeCommerce(
            $request,
            $workspace,
            fn (CommerceOrderAccess $access, User $user): bool => $access->canCancelOrders($user, $workspace),
            'لا تملك صلاحية إلغاء طلبات التجارة.',
        );
    }

    /**
     * @param  callable(CommerceOrderAccess,User):bool  $allows
     */
    private function authorizeCommerce(
        Request $request,
        Workspace $workspace,
        callable $allows,
        string $message,
    ): User {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }
        if (! $allows(app(CommerceOrderAccess::class), $user)) {
            throw new HttpResponseException($this->fail($message, 403));
        }

        return $user;
    }
}
