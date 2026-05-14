<?php

namespace App\Providers;

use App\Contracts\WhatsappServiceInterface;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\User;
use App\Policies\BikerPolicy;
use App\Policies\RestaurantPolicy;
use App\Policies\ShiftPolicy;
use App\Services\WhatsappLogService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsappServiceInterface::class, WhatsappLogService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Role-based gates
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('restaurant-manager', fn (User $user) => $user->isRestaurantManager());
        Gate::define('biker', fn (User $user) => $user->isBiker());

        // Business rule gates
        Gate::define('release-payment', fn (User $user) => $user->isAdmin());       // BR-03
        Gate::define('manage-shift-bikers', fn (User $user) => $user->isAdmin());    // BR-05

        // Model policies
        Gate::policy(Shift::class, ShiftPolicy::class);
        Gate::policy(Restaurant::class, RestaurantPolicy::class);
        Gate::policy(Biker::class, BikerPolicy::class);
    }
}
