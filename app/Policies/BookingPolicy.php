<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use App\Support\Booking\BookingAccess;
use App\Support\Tenancy\WorkspaceContext;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null && app(BookingAccess::class)->canViewBookings($user, $workspace);
    }

    public function view(User $user, Booking $booking): bool
    {
        return app(BookingAccess::class)->canViewBookings($user, $booking->workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null && app(BookingAccess::class)->canManageBookings($user, $workspace);
    }

    public function update(User $user, Booking $booking): bool
    {
        return app(BookingAccess::class)->canManageBookings($user, $booking->workspace);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return app(BookingAccess::class)->canCancelBooking($user, $booking->workspace, $booking);
    }

    public function manageSettings(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null && app(BookingAccess::class)->canManageSettings($user, $workspace);
    }
}
