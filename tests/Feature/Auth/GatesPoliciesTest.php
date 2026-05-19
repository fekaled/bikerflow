<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Gates and Policies Tests
 *
 * Validates role-based gates and model policies for authorization.
 *
 * Acceptance Criteria: AC-26 through AC-35
 * Business Rules: BR-03 (Admin-only release), BR-05 (Admin-only manage bikers)
 */
class GatesPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $restaurantManager;

    private User $bikerUser;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private Biker $biker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->otherRestaurant = Restaurant::factory()->create();
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
    // AC-26: Gate 'admin' returns true only for Admin users
    // ========================================================================

    /**
     * AC-26: Admin gate allows Admin users.
     */
    public function test_admin_gate_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('admin'),
            'AC-26: admin gate must allow Admin users'
        );
    }

    /**
     * AC-26: Admin gate denies RestaurantManager users.
     */
    public function test_admin_gate_denies_restaurant_manager(): void
    {
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('admin'),
            'AC-26: admin gate must deny RestaurantManager users'
        );
    }

    /**
     * AC-26: Admin gate denies Biker users.
     */
    public function test_admin_gate_denies_biker(): void
    {
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('admin'),
            'AC-26: admin gate must deny Biker users'
        );
    }

    // ========================================================================
    // AC-27: Gate 'restaurant-manager' returns true only for RestaurantManager
    // ========================================================================

    /**
     * AC-27: restaurant-manager gate allows RestaurantManager users.
     */
    public function test_restaurant_manager_gate_allows_rm(): void
    {
        $this->assertTrue(
            Gate::forUser($this->restaurantManager)->allows('restaurant-manager'),
            'AC-27: restaurant-manager gate must allow RestaurantManager users'
        );
    }

    /**
     * AC-27: restaurant-manager gate denies Admin users.
     */
    public function test_restaurant_manager_gate_denies_admin(): void
    {
        $this->assertFalse(
            Gate::forUser($this->admin)->allows('restaurant-manager'),
            'AC-27: restaurant-manager gate must deny Admin users'
        );
    }

    /**
     * AC-27: restaurant-manager gate denies Biker users.
     */
    public function test_restaurant_manager_gate_denies_biker(): void
    {
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('restaurant-manager'),
            'AC-27: restaurant-manager gate must deny Biker users'
        );
    }

    // ========================================================================
    // AC-28: Gate 'biker' returns true only for Biker users
    // ========================================================================

    /**
     * AC-28: biker gate allows Biker users.
     */
    public function test_biker_gate_allows_biker(): void
    {
        $this->assertTrue(
            Gate::forUser($this->bikerUser)->allows('biker'),
            'AC-28: biker gate must allow Biker users'
        );
    }

    /**
     * AC-28: biker gate denies Admin users.
     */
    public function test_biker_gate_denies_admin(): void
    {
        $this->assertFalse(
            Gate::forUser($this->admin)->allows('biker'),
            'AC-28: biker gate must deny Admin users'
        );
    }

    /**
     * AC-28: biker gate denies RestaurantManager users.
     */
    public function test_biker_gate_denies_restaurant_manager(): void
    {
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('biker'),
            'AC-28: biker gate must deny RestaurantManager users'
        );
    }

    // ========================================================================
    // AC-29: Gate 'release-payment' returns true only for Admin (BR-03)
    // ========================================================================

    /**
     * AC-29: release-payment gate allows Admin users (BR-03).
     */
    public function test_release_payment_gate_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('release-payment'),
            'AC-29: release-payment gate must allow Admin users (BR-03)'
        );
    }

    /**
     * AC-29: release-payment gate denies RestaurantManager users.
     */
    public function test_release_payment_gate_denies_rm(): void
    {
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('release-payment'),
            'AC-29: release-payment gate must deny RestaurantManager users'
        );
    }

    /**
     * AC-29: release-payment gate denies Biker users.
     */
    public function test_release_payment_gate_denies_biker(): void
    {
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('release-payment'),
            'AC-29: release-payment gate must deny Biker users'
        );
    }

    // ========================================================================
    // AC-30: Gate 'manage-shift-bikers' returns true only for Admin (BR-05)
    // ========================================================================

    /**
     * AC-30: manage-shift-bikers gate allows Admin users (BR-05).
     */
    public function test_manage_shift_bikers_gate_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('manage-shift-bikers'),
            'AC-30: manage-shift-bikers gate must allow Admin users (BR-05)'
        );
    }

    /**
     * AC-30: manage-shift-bikers gate denies RestaurantManager users.
     */
    public function test_manage_shift_bikers_gate_denies_rm(): void
    {
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('manage-shift-bikers'),
            'AC-30: manage-shift-bikers gate must deny RestaurantManager users'
        );
    }

    /**
     * AC-30: manage-shift-bikers gate denies Biker users.
     */
    public function test_manage_shift_bikers_gate_denies_biker(): void
    {
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('manage-shift-bikers'),
            'AC-30: manage-shift-bikers gate must deny Biker users'
        );
    }

    // ========================================================================
    // AC-31: ShiftPolicy@create allows Admin and RestaurantManager, denies Biker
    // ========================================================================

    /**
     * AC-31: Admin can create shifts.
     */
    public function test_shift_policy_create_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('create', Shift::class),
            'AC-31: Admin must be allowed to create shifts'
        );
    }

    /**
     * AC-31: RestaurantManager can create shifts.
     */
    public function test_shift_policy_create_allows_restaurant_manager(): void
    {
        $this->assertTrue(
            Gate::forUser($this->restaurantManager)->allows('create', Shift::class),
            'AC-31: RestaurantManager must be allowed to create shifts'
        );
    }

    /**
     * AC-31: Biker cannot create shifts.
     */
    public function test_shift_policy_create_denies_biker(): void
    {
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('create', Shift::class),
            'AC-31: Biker must be denied from creating shifts'
        );
    }

    // ========================================================================
    // AC-32: ShiftPolicy@update allows Admin always; RM only own restaurant
    // ========================================================================

    /**
     * AC-32: Admin can update any shift.
     */
    public function test_shift_policy_update_allows_admin_any_shift(): void
    {
        $ownShift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);
        $otherShift = Shift::factory()->create([
            'restaurant_id' => $this->otherRestaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->admin)->allows('update', $ownShift),
            'AC-32: Admin must be able to update shifts from any restaurant'
        );
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('update', $otherShift),
            'AC-32: Admin must be able to update shifts from other restaurants'
        );
    }

    /**
     * AC-32: RestaurantManager can update own restaurant's shifts.
     */
    public function test_shift_policy_update_allows_rm_own_restaurant(): void
    {
        $ownShift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->restaurantManager)->allows('update', $ownShift),
            'AC-32: RestaurantManager must be able to update own restaurant shifts'
        );
    }

    /**
     * AC-32: RestaurantManager cannot update other restaurant's shifts.
     */
    public function test_shift_policy_update_denies_rm_other_restaurant(): void
    {
        $otherShift = Shift::factory()->create([
            'restaurant_id' => $this->otherRestaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('update', $otherShift),
            'AC-32: RestaurantManager must be denied from updating other restaurant shifts'
        );
    }

    /**
     * AC-32: Biker cannot update shifts.
     */
    public function test_shift_policy_update_denies_biker(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('update', $shift),
            'AC-32: Biker must be denied from updating shifts'
        );
    }

    // ========================================================================
    // AC-33: ShiftPolicy@delete allows Admin only
    // ========================================================================

    /**
     * AC-33: Admin can delete shifts.
     */
    public function test_shift_policy_delete_allows_admin(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->admin)->allows('delete', $shift),
            'AC-33: Admin must be allowed to delete shifts'
        );
    }

    /**
     * AC-33: RestaurantManager cannot delete shifts.
     */
    public function test_shift_policy_delete_denies_restaurant_manager(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('delete', $shift),
            'AC-33: RestaurantManager must be denied from deleting shifts'
        );
    }

    /**
     * AC-33: Biker cannot delete shifts.
     */
    public function test_shift_policy_delete_denies_biker(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('delete', $shift),
            'AC-33: Biker must be denied from deleting shifts'
        );
    }

    // ========================================================================
    // AC-34: ShiftPolicy@addBiker allows Admin only (BR-05)
    // ========================================================================

    /**
     * AC-34: Admin can add bikers to shifts (BR-05).
     */
    public function test_shift_policy_add_biker_allows_admin(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->admin)->allows('addBiker', $shift),
            'AC-34: Admin must be allowed to add bikers to shifts (BR-05)'
        );
    }

    /**
     * AC-34: RestaurantManager cannot add bikers to shifts (BR-05).
     */
    public function test_shift_policy_add_biker_denies_rm(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('addBiker', $shift),
            'AC-34: RestaurantManager must be denied from adding bikers (BR-05)'
        );
    }

    /**
     * AC-34: Biker cannot add bikers to shifts (BR-05).
     */
    public function test_shift_policy_add_biker_denies_biker(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('addBiker', $shift),
            'AC-34: Biker must be denied from adding bikers (BR-05)'
        );
    }

    // ========================================================================
    // AC-35: ShiftPolicy@view — Admin always, RM own restaurant, Biker read-only
    // ========================================================================

    /**
     * AC-35: Admin can view any shift.
     */
    public function test_shift_policy_view_allows_admin_any_shift(): void
    {
        $ownShift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);
        $otherShift = Shift::factory()->create([
            'restaurant_id' => $this->otherRestaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->admin)->allows('view', $ownShift),
            'AC-35: Admin must view shifts from any restaurant'
        );
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('view', $otherShift),
            'AC-35: Admin must view shifts from other restaurants'
        );
    }

    /**
     * AC-35: RestaurantManager can view own restaurant's shifts.
     */
    public function test_shift_policy_view_allows_rm_own_restaurant(): void
    {
        $ownShift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->restaurantManager)->allows('view', $ownShift),
            'AC-35: RestaurantManager must view own restaurant shifts'
        );
    }

    /**
     * AC-35: RestaurantManager cannot view other restaurant's shifts.
     */
    public function test_shift_policy_view_denies_rm_other_restaurant(): void
    {
        $otherShift = Shift::factory()->create([
            'restaurant_id' => $this->otherRestaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('view', $otherShift),
            'AC-35: RestaurantManager must be denied from viewing other restaurant shifts'
        );
    }

    /**
     * AC-35: Biker can view shifts (read-only access).
     */
    public function test_shift_policy_view_allows_biker_read_only(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertTrue(
            Gate::forUser($this->bikerUser)->allows('view', $shift),
            'AC-35: Biker must be allowed to view shifts (read-only)'
        );
    }

    // ========================================================================
    // Restaurant Policy Tests
    // ========================================================================

    /**
     * Admin can view any restaurant.
     */
    public function test_restaurant_policy_view_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('view', $this->restaurant),
            'Admin must be allowed to view any restaurant'
        );
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('view', $this->otherRestaurant),
            'Admin must be allowed to view other restaurants'
        );
    }

    /**
     * RestaurantManager can view their own restaurant only.
     */
    public function test_restaurant_policy_view_allows_rm_own_only(): void
    {
        $this->assertTrue(
            Gate::forUser($this->restaurantManager)->allows('view', $this->restaurant),
            'RestaurantManager must view own restaurant'
        );
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('view', $this->otherRestaurant),
            'RestaurantManager must be denied from viewing other restaurants'
        );
    }

    /**
     * Admin can update any restaurant.
     */
    public function test_restaurant_policy_update_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('update', $this->restaurant),
            'Admin must be allowed to update any restaurant'
        );
    }

    /**
     * RestaurantManager cannot update restaurants.
     */
    public function test_restaurant_policy_update_denies_rm(): void
    {
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('update', $this->restaurant),
            'RestaurantManager must be denied from updating restaurants'
        );
    }

    /**
     * Admin can delete restaurants.
     */
    public function test_restaurant_policy_delete_allows_admin_only(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('delete', $this->restaurant),
            'Admin must be allowed to delete restaurants'
        );
        $this->assertFalse(
            Gate::forUser($this->restaurantManager)->allows('delete', $this->restaurant),
            'RestaurantManager must be denied from deleting restaurants'
        );
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('delete', $this->restaurant),
            'Biker must be denied from deleting restaurants'
        );
    }

    // ========================================================================
    // Biker Policy Tests
    // ========================================================================

    /**
     * Admin can view any biker.
     */
    public function test_biker_policy_view_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('view', $this->biker),
            'Admin must be allowed to view any biker'
        );
    }

    /**
     * Biker can view their own profile.
     */
    public function test_biker_policy_view_allows_own_biker(): void
    {
        $this->assertTrue(
            Gate::forUser($this->bikerUser)->allows('view', $this->biker),
            'Biker must be allowed to view own profile'
        );
    }

    /**
     * Admin can update any biker.
     */
    public function test_biker_policy_update_allows_admin(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('update', $this->biker),
            'Admin must be allowed to update any biker'
        );
    }

    /**
     * Biker cannot update their own profile (admin-managed).
     */
    public function test_biker_policy_update_denies_biker(): void
    {
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('update', $this->biker),
            'Biker must be denied from updating own profile (admin-managed)'
        );
    }

    /**
     * Admin can delete any biker.
     */
    public function test_biker_policy_delete_allows_admin_only(): void
    {
        $this->assertTrue(
            Gate::forUser($this->admin)->allows('delete', $this->biker),
            'Admin must be allowed to delete bikers'
        );
        $this->assertFalse(
            Gate::forUser($this->bikerUser)->allows('delete', $this->biker),
            'Biker must be denied from deleting bikers'
        );
    }

    // ========================================================================
    // Edge Case: RestaurantManager with null restaurant_id
    // ========================================================================

    /**
     * RestaurantManager with null restaurant_id is denied from all shift operations.
     */
    public function test_rm_with_null_restaurant_id_denied_from_shifts(): void
    {
        $orphanManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => null,
        ]);

        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->assertFalse(
            Gate::forUser($orphanManager)->allows('view', $shift),
            'RestaurantManager with null restaurant_id must be denied from viewing shifts'
        );
        $this->assertFalse(
            Gate::forUser($orphanManager)->allows('update', $shift),
            'RestaurantManager with null restaurant_id must be denied from updating shifts'
        );
    }
}
