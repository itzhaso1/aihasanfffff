<?php

declare(strict_types=1);

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;

final class BookingAccess
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function canViewBookings(User $user, Workspace $workspace): bool
    {
        if (! $this->isMember($user, $workspace)) {
            return false;
        }

        return $this->workspaceAccess->hasAnyMembershipRole($user, $workspace, [
            WorkspaceAccess::ROLE_OWNER,
            WorkspaceAccess::ROLE_ADMIN,
            WorkspaceAccess::ROLE_MANAGER,
            WorkspaceAccess::ROLE_AGENT,
        ])
            || $user->can('bookings.view')
            || $user->can('bookings.manage');
    }

    public function canManageBookings(User $user, Workspace $workspace): bool
    {
        if (! $this->isMember($user, $workspace)) {
            return false;
        }

        return $this->workspaceAccess->isElevated($user, $workspace)
            || $user->can('bookings.manage')
            || $user->hasRole(WorkspaceAccess::ROLE_RECEPTIONIST);
    }

    public function canCancelBooking(User $user, Workspace $workspace, ?Booking $booking = null): bool
    {
        if (! $this->isMember($user, $workspace)) {
            return false;
        }

        if ($booking !== null && (int) $booking->workspace_id !== (int) $workspace->id) {
            return false;
        }

        return $user->can('bookings.cancel')
            || $this->canManageBookings($user, $workspace);
    }

    public function canManageSettings(User $user, Workspace $workspace): bool
    {
        if (! $this->isMember($user, $workspace)) {
            return false;
        }

        return $this->workspaceAccess->isElevated($user, $workspace)
            || $user->can('bookings.settings.manage');
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $workspace)
            || $this->workspaceAccess->isWorkspaceOwner($user, $workspace);
    }
}
