<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role Middleware Tests
 *
 * Validates the EnsureUserRole middleware for all three roles.
 *
 * Acceptance Criteria: AC-36 through AC-39
 * Business Rules: BR-05 (Admin-only operations), BR-03 (Admin-only release)
 */
class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $restaurantManager;

    private User $bikerUser;

    private Restaurant $restaurant;

    private Biker $biker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->biker = Biker::factory()->create();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurantManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->bikerUser = User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $this->biker->id,
        ]);
    }

    // ========================================================================
    // AC-36: role:admin middleware grants access to Admin only
    // ========================================================================

    /**
     * AC-36: Admin can access role:admin protected route.
     */
    public function test_admin_can_access_admin_route(): void
    {
        // We test by accessing a route that uses the role:admin middleware
        // Using a generic test route approach — register on-the-fly
        $this->app['router']->get('/_test/admin-only', fn () => response('OK'))
            ->middleware(['auth', 'role:admin']);

        $response = $this->actingAs($this->admin)->get('/_test/admin-only');

        $response->assertOk();
    }

    /**
     * AC-36: RestaurantManager is denied from role:admin route (403).
     */
    public function test_restaurant_manager_denied_from_admin_route(): void
    {
        $this->app['router']->get('/_test/admin-only-2', fn () => response('OK'))
            ->middleware(['auth', 'role:admin']);

        $response = $this->actingAs($this->restaurantManager)->get('/_test/admin-only-2');

        $response->assertForbidden();
    }

    /**
     * AC-36: Biker is denied from role:admin route (403).
     */
    public function test_biker_denied_from_admin_route(): void
    {
        $this->app['router']->get('/_test/admin-only-3', fn () => response('OK'))
            ->middleware(['auth', 'role:admin']);

        $response = $this->actingAs($this->bikerUser)->get('/_test/admin-only-3');

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-37: role:restaurant_manager middleware grants access to RestaurantManager only
    // ========================================================================

    /**
     * AC-37: RestaurantManager can access role:restaurant_manager protected route.
     */
    public function test_restaurant_manager_can_access_rm_route(): void
    {
        $this->app['router']->get('/_test/rm-only', fn () => response('OK'))
            ->middleware(['auth', 'role:restaurant_manager']);

        $response = $this->actingAs($this->restaurantManager)->get('/_test/rm-only');

        $response->assertOk();
    }

    /**
     * AC-37: Admin is denied from role:restaurant_manager route (403).
     */
    public function test_admin_denied_from_rm_route(): void
    {
        $this->app['router']->get('/_test/rm-only-2', fn () => response('OK'))
            ->middleware(['auth', 'role:restaurant_manager']);

        $response = $this->actingAs($this->admin)->get('/_test/rm-only-2');

        $response->assertForbidden();
    }

    /**
     * AC-37: Biker is denied from role:restaurant_manager route (403).
     */
    public function test_biker_denied_from_rm_route(): void
    {
        $this->app['router']->get('/_test/rm-only-3', fn () => response('OK'))
            ->middleware(['auth', 'role:restaurant_manager']);

        $response = $this->actingAs($this->bikerUser)->get('/_test/rm-only-3');

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-38: role:biker middleware grants access to Biker only
    // ========================================================================

    /**
     * AC-38: Biker can access role:biker protected route.
     */
    public function test_biker_can_access_biker_route(): void
    {
        $this->app['router']->get('/_test/biker-only', fn () => response('OK'))
            ->middleware(['auth', 'role:biker']);

        $response = $this->actingAs($this->bikerUser)->get('/_test/biker-only');

        $response->assertOk();
    }

    /**
     * AC-38: Admin is denied from role:biker route (403).
     */
    public function test_admin_denied_from_biker_route(): void
    {
        $this->app['router']->get('/_test/biker-only-2', fn () => response('OK'))
            ->middleware(['auth', 'role:biker']);

        $response = $this->actingAs($this->admin)->get('/_test/biker-only-2');

        $response->assertForbidden();
    }

    /**
     * AC-38: RestaurantManager is denied from role:biker route (403).
     */
    public function test_restaurant_manager_denied_from_biker_route(): void
    {
        $this->app['router']->get('/_test/biker-only-3', fn () => response('OK'))
            ->middleware(['auth', 'role:biker']);

        $response = $this->actingAs($this->restaurantManager)->get('/_test/biker-only-3');

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-39: Unauthenticated user hitting role middleware → redirect to /login
    // ========================================================================

    /**
     * AC-39: Unauthenticated user is redirected to /login.
     */
    public function test_unauthenticated_user_redirected_to_login_from_role_route(): void
    {
        $this->app['router']->get('/_test/admin-protected', fn () => response('OK'))
            ->middleware(['auth', 'role:admin']);

        $response = $this->get('/_test/admin-protected');

        $response->assertRedirect('/login');
    }

    /**
     * AC-39: Unauthenticated user redirected from restaurant_manager route.
     */
    public function test_unauthenticated_user_redirected_from_rm_route(): void
    {
        $this->app['router']->get('/_test/rm-protected', fn () => response('OK'))
            ->middleware(['auth', 'role:restaurant_manager']);

        $response = $this->get('/_test/rm-protected');

        $response->assertRedirect('/login');
    }

    /**
     * AC-39: Unauthenticated user redirected from biker route.
     */
    public function test_unauthenticated_user_redirected_from_biker_route(): void
    {
        $this->app['router']->get('/_test/biker-protected', fn () => response('OK'))
            ->middleware(['auth', 'role:biker']);

        $response = $this->get('/_test/biker-protected');

        $response->assertRedirect('/login');
    }

    // ========================================================================
    // Middleware registration tests
    // ========================================================================

    /**
     * 'role' middleware alias is registered in the application.
     */
    public function test_role_middleware_alias_is_registered(): void
    {
        $router = $this->app['router'];
        $middlewareAliases = $router->getMiddleware();

        $this->assertArrayHasKey('role', $middlewareAliases,
            "The 'role' middleware alias must be registered in the application");
    }

    /**
     * role middleware supports multiple roles (e.g., role:admin,restaurant_manager).
     */
    public function test_role_middleware_supports_multiple_roles(): void
    {
        $this->app['router']->get('/_test/multi-role', fn () => response('OK'))
            ->middleware(['auth', 'role:admin,restaurant_manager']);

        // Admin can access
        $response = $this->actingAs($this->admin)->get('/_test/multi-role');
        $response->assertOk();

        // RestaurantManager can access
        $response = $this->actingAs($this->restaurantManager)->get('/_test/multi-role');
        $response->assertOk();

        // Biker cannot
        $response = $this->actingAs($this->bikerUser)->get('/_test/multi-role');
        $response->assertForbidden();
    }
}
