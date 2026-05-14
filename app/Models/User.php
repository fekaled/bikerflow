<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'restaurant_id', 'biker_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function biker()
    {
        return $this->belongsTo(Biker::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isRestaurantManager(): bool
    {
        return $this->role === UserRole::RestaurantManager;
    }

    public function isBiker(): bool
    {
        return $this->role === UserRole::Biker;
    }

    public function managedRestaurant(): ?Restaurant
    {
        if ($this->role === UserRole::RestaurantManager) {
            return $this->restaurant;
        }

        return null;
    }

    public function bikerProfile(): ?Biker
    {
        if ($this->role === UserRole::Biker) {
            return $this->biker;
        }

        return null;
    }
}
