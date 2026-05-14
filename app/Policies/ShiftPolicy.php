<?php

namespace App\Policies;

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
}
