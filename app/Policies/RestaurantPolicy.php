<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Restaurant $restaurant): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isRestaurantManager()) {
            return $restaurant->id === $user->restaurant_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Restaurant $restaurant): bool
    {
        return $user->isAdmin();
    }
}
