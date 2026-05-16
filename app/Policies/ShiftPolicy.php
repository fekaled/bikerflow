<?php

namespace App\Policies;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Shift $shift): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isRestaurantManager()) {
            return $shift->restaurant_id === $user->restaurant_id;
        }

        if ($user->isBiker()) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isRestaurantManager();
    }

    public function update(User $user, Shift $shift): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isRestaurantManager()) {
            return $shift->restaurant_id === $user->restaurant_id;
        }

        return false;
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->isAdmin();
    }

    /**
     * BR-05: Only Admin can add/replace bikers once a shift has been initiated.
     */
    public function addBiker(User $user, Shift $shift): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only Admin can close shifts.
     */
    public function close(User $user, Shift $shift): bool
    {
        return $user->isAdmin();
    }

    /**
     * Phase 3A: Only Admin can review shift close.
     */
    public function reviewClose(User $user, Shift $shift): bool
    {
        return $user->isAdmin();
    }

    /**
     * Phase 2D: Live Tick Tracking authorization.
     *
     * Admin can tick any open shift.
     * Restaurant Manager can tick their own restaurant's open shifts.
     */
    public function tick(User $user, Shift $shift): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isRestaurantManager()) {
            return $shift->status === ShiftStatus::Open
                && $shift->restaurant_id === $user->restaurant_id;
        }

        return false;
    }

    /**
     * Phase 2E: End-of-Shift Entry authorization.
     *
     * Admin can submit trips for any shift.
     * Restaurant Manager can submit trips for their own restaurant's open shifts.
     */
    public function submitTrips(User $user, Shift $shift): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isRestaurantManager()) {
            return $shift->status === ShiftStatus::Open
                && $shift->restaurant_id === $user->restaurant_id;
        }

        return false;
    }
}
