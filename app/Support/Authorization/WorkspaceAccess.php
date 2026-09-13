<?php

namespace App\Support\Authorization;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;

class WorkspaceAccess
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLE_RECEPTIONIST = 'receptionist';

    public const ROLE_AGENT = 'agent';

    public const ROLE_MEMBER = 'member';

    public const ROLE_STAFF_DOCTOR = 'staff_doctor';

    public const ROLE_STAFF = 'staff';

    /**
     * Values allowed on workspace_users.membership_role (DB enum).
     *
     * @var array<int, string>
     */
    public const MEMBERSHIP_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_MANAGER,
        self::ROLE_AGENT,
        self::ROLE_MEMBER,
    ];

    /**
     * @var array<string, int>
     */
    public const RANK = [
        self::ROLE_OWNER => 100,
        self::ROLE_ADMIN => 80,
        self::ROLE_MANAGER => 60,
        self::ROLE_ACCOUNTANT => 40,
        self::ROLE_RECEPTIONIST => 40,
        self::ROLE_AGENT => 20,
        self::ROLE_STAFF_DOCTOR => 20,
        self::ROLE_STAFF => 10,
        self::ROLE_MEMBER => 10,
    ];

    /**
     * @var array<int, string>
     */
    public const ASSIGNABLE_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_MANAGER,
        self::ROLE_ACCOUNTANT,
        self::ROLE_RECEPTIONIST,
        self::ROLE_AGENT,
        self::ROLE_STAFF_DOCTOR,
        self::ROLE_STAFF,
        self::ROLE_MEMBER,
    ];

    public function membershipRole(User $user, Workspace $workspace): ?string
    {
        $membership = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->first();

        $role = $membership?->pivot?->membership_role;

        return is_string($role) && $role !== '' ? $role : null;
    }

    public function rank(?string $role): int
    {
        if ($role === null) {
            return 0;
        }

        return self::RANK[$role] ?? 0;
    }

    /**
     * Map invite/Spatie role names onto the workspace_users enum.
     */
    public function persistableMembershipRole(string $role): string
    {
        $normalized = strtolower(trim($role));

        return match ($normalized) {
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
            self::ROLE_AGENT,
            self::ROLE_MEMBER => $normalized,
            self::ROLE_RECEPTIONIST => self::ROLE_AGENT,
            default => self::ROLE_MEMBER,
        };
    }

    public function isActiveMember(User $user, Workspace $workspace): bool
    {
        return $this->membershipRole($user, $workspace) !== null;
    }

    public function isWorkspaceOwner(User $user, Workspace $workspace): bool
    {
        return (int) $workspace->owner_user_id === (int) $user->id
            || $this->membershipRole($user, $workspace) === self::ROLE_OWNER;
    }

    public function hasAnyMembershipRole(User $user, Workspace $workspace, array $roles): bool
    {
        $role = $this->membershipRole($user, $workspace);

        return $role !== null && in_array($role, $roles, true);
    }

    public function isElevated(User $user, Workspace $workspace): bool
    {
        return $this->hasAnyMembershipRole($user, $workspace, [
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
        ]);
    }

    public function canManageMembers(User $user, Workspace $workspace): bool
    {
        if (! $this->isActiveMember($user, $workspace)) {
            return false;
        }

        if ($this->isWorkspaceOwner($user, $workspace)) {
            return true;
        }

        if ($this->hasAnyMembershipRole($user, $workspace, [self::ROLE_ADMIN])) {
            return true;
        }

        return $user->can('employees.manage')
            && $this->hasAnyMembershipRole($user, $workspace, [self::ROLE_ADMIN, self::ROLE_OWNER]);
    }

    public function canManageApiKeys(User $user, Workspace $workspace): bool
    {
        if (! $this->isActiveMember($user, $workspace)) {
            return false;
        }

        return $this->hasAnyMembershipRole($user, $workspace, [self::ROLE_OWNER, self::ROLE_ADMIN])
            || $user->can('workspace.manage');
    }

    public function canManageInventory(User $user, Workspace $workspace): bool
    {
        if (! $this->isActiveMember($user, $workspace)) {
            return false;
        }

        if ($user->can('inventory.manage')) {
            return true;
        }

        return $this->hasAnyMembershipRole($user, $workspace, [
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
        ]);
    }

    public function canViewInventory(User $user, Workspace $workspace): bool
    {
        if ($this->canManageInventory($user, $workspace)) {
            return true;
        }

        return $this->isActiveMember($user, $workspace)
            && ($user->can('products.manage') || $user->can('orders.manage'));
    }

    public function canManagePaymentGateways(User $user, Workspace $workspace): bool
    {
        if (! $this->isActiveMember($user, $workspace)) {
            return false;
        }

        return $this->hasAnyMembershipRole($user, $workspace, [self::ROLE_OWNER, self::ROLE_ADMIN])
            || $user->can('payments.manage');
    }

    public function canAssignRole(User $actor, Workspace $workspace, string $role, User $target): bool
    {
        if (! $this->canManageMembers($actor, $workspace)) {
            return false;
        }

        if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
            return false;
        }

        if (! $this->isActiveMember($target, $workspace)) {
            return false;
        }

        $actorRole = $this->membershipRole($actor, $workspace);
        $actorRank = $this->isWorkspaceOwner($actor, $workspace)
            ? self::RANK[self::ROLE_OWNER]
            : $this->rank($actorRole);
        $targetRoleRank = $this->rank($role);

        if ($targetRoleRank >= $actorRank) {
            return false;
        }

        return $role === self::ROLE_ADMIN && ! $this->isWorkspaceOwner($actor, $workspace)
            ? false
            : true;
    }

    public function canInviteRole(User $actor, Workspace $workspace, string $role): bool
    {
        if (! $this->canManageMembers($actor, $workspace)) {
            return false;
        }

        if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
            return false;
        }

        $actorRank = $this->isWorkspaceOwner($actor, $workspace)
            ? self::RANK[self::ROLE_OWNER]
            : $this->rank($this->membershipRole($actor, $workspace));

        if ($this->rank($role) >= $actorRank) {
            return false;
        }

        return ! ($role === self::ROLE_ADMIN && ! $this->isWorkspaceOwner($actor, $workspace));
    }

    public function canModifyMembership(User $actor, Workspace $workspace, User $target, ?string $newRole = null): bool
    {
        if (! $this->canManageMembers($actor, $workspace)) {
            return false;
        }

        if ((int) $target->id === (int) $workspace->owner_user_id) {
            return false;
        }

        if ((int) $target->id === (int) $actor->id && ! $this->isWorkspaceOwner($actor, $workspace)) {
            return false;
        }

        if (! $this->isActiveMember($target, $workspace) && $newRole === null) {
            return false;
        }

        $actorRank = $this->isWorkspaceOwner($actor, $workspace)
            ? self::RANK[self::ROLE_OWNER]
            : $this->rank($this->membershipRole($actor, $workspace));
        $targetRank = $this->rank($this->membershipRole($target, $workspace));

        if ($targetRank >= $actorRank) {
            return false;
        }

        if ($newRole !== null) {
            return $this->canInviteRole($actor, $workspace, $newRole);
        }

        return true;
    }

    public function canSyncPermissions(User $actor, Workspace $workspace): bool
    {
        return $this->isWorkspaceOwner($actor, $workspace)
            || $this->hasAnyMembershipRole($actor, $workspace, [self::ROLE_ADMIN]);
    }

    public function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->workspace();
    }
}
