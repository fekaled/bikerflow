<?php

namespace App\Policies;

use App\Models\Biker;
use App\Models\User;

class BikerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Biker $biker): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isBiker()) {
            return $biker->id === $user->biker_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Biker $biker): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Biker $biker): bool
    {
        return $user->isAdmin();
    }
}
