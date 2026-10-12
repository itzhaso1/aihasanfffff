<?php

namespace App\Http\Controllers\Api\Mobile\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Chat\ChatCatalogAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait AuthorizesChatCatalog
{
    protected function authorizeChatCatalogView(Request $request, Workspace $workspace): User
    {
        return $this->authorizeChatCatalog($request, $workspace, manage: false);
    }

    protected function authorizeChatCatalogManage(Request $request, Workspace $workspace): User
    {
        return $this->authorizeChatCatalog($request, $workspace, manage: true);
    }

    protected function authorizeChatCatalogItemsManage(Request $request, Workspace $workspace): User
    {
        return $this->authorizeWithAccess(
            $request,
            $workspace,
            fn (ChatCatalogAccess $access, User $user): bool => $access->canManageItems($user, $workspace),
            'لا تملك صلاحية إدارة المنتجات.',
        );
    }

    protected function authorizeChatCatalogCategoriesManage(Request $request, Workspace $workspace): User
    {
        return $this->authorizeWithAccess(
            $request,
            $workspace,
            fn (ChatCatalogAccess $access, User $user): bool => $access->canManageCategories($user, $workspace),
            'لا تملك صلاحية إدارة التصنيفات.',
        );
    }

    protected function authorizeChatCatalogInventoryManage(Request $request, Workspace $workspace): User
    {
        return $this->authorizeWithAccess(
            $request,
            $workspace,
            fn (ChatCatalogAccess $access, User $user): bool => $access->canManageInventory($user, $workspace),
            'لا تملك صلاحية إدارة المخزون.',
        );
    }

    protected function authorizeChatCatalogSettingsManage(Request $request, Workspace $workspace): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }
        if (! app(ChatCatalogAccess::class)->canManageSettings($user, $workspace)) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية إعدادات المنتجات والمخزون.', 403));
        }

        return $user;
    }

    protected function authorizeChatCatalogDigitalInventoryManage(Request $request, Workspace $workspace): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }
        if (! app(ChatCatalogAccess::class)->canManageDigitalInventory($user, $workspace)) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية المخزون الرقمي.', 403));
        }

        return $user;
    }

    protected function authorizeChatCatalogDigitalInventoryView(Request $request, Workspace $workspace): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }
        if (! app(ChatCatalogAccess::class)->canViewDigitalInventory($user, $workspace)) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية عرض المخزون الرقمي.', 403));
        }

        return $user;
    }

    /**
     * @param  callable(ChatCatalogAccess, User): bool  $allows
     */
    private function authorizeWithAccess(Request $request, Workspace $workspace, callable $allows, string $message): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }
        if (! $allows(app(ChatCatalogAccess::class), $user)) {
            throw new HttpResponseException($this->fail($message, 403));
        }

        return $user;
    }

    protected function authorizeChatCatalog(Request $request, Workspace $workspace, bool $manage): User
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw new HttpResponseException($this->fail('غير مصرح.', 401));
        }

        $access = app(ChatCatalogAccess::class);
        $allowed = $manage
            ? $access->canManage($user, $workspace)
            : $access->canView($user, $workspace);

        if (! $allowed) {
            throw new HttpResponseException($this->fail('لا تملك صلاحية المنتجات والمخزون.', 403));
        }

        return $user;
    }
}
