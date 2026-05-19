<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User Model Tests
 *
 * Validates User model role casting, entity relationships, and helper methods.
 *
 * Acceptance Criteria: AC-11 through AC-16
 * Business Rules: BR-05 (role-based authorization)
 */
class UserModelTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // AC-11: User model casts role to UserRole enum
    // ========================================================================

    /**
     * AC-11: User model casts the role attribute to UserRole enum.
     */
    public function test_user_casts_role_to_user_role_enum(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $fresh = User::find($user->id);
        $this->assertInstanceOf(UserRole::class, $fresh->role,
            'AC-11: User->role must be cast to UserRole enum');
        $this->assertEquals(UserRole::Admin, $fresh->role,
            'AC-11: User->role must equal UserRole::Admin');
    }

    /**
     * AC-11: User model can be created with RestaurantManager role.
     */
    public function test_user_casts_restaurant_manager_role(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::RestaurantManager,
        ]);

        $fresh = User::find($user->id);
        $this->assertEquals(UserRole::RestaurantManager, $fresh->role,
            'AC-11: User->role must equal UserRole::RestaurantManager');
    }

    /**
     * AC-11: User model can be created with Biker role.
     */
    public function test_user_casts_biker_role(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Biker,
        ]);

        $fresh = User::find($user->id);
        $this->assertEquals(UserRole::Biker, $fresh->role,
            'AC-11: User->role must equal UserRole::Biker');
    }

    // ========================================================================
    // AC-12: User has belongsTo relationship restaurant() (nullable)
    // ========================================================================

    /**
     * AC-12: User model has restaurant() relationship method.
     */
    public function test_user_has_restaurant_relationship(): void
    {
        $user = new User;
        $this->assertTrue(
            method_exists($user, 'restaurant'),
            'AC-12: User model must have a restaurant() method'
        );
    }

    /**
     * AC-12: User with restaurant_id set returns the related restaurant.
     */
    public function test_user_restaurant_relationship_returns_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();

        $user = User::factory()->create([
            'role' => UserRole::RestaurantManager,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->assertNotNull($user->restaurant,
            'AC-12: User->restaurant must not be null when restaurant_id is set');
        $this->assertEquals($restaurant->id, $user->restaurant->id,
            'AC-12: User->restaurant must return the correct restaurant');
        $this->assertInstanceOf(Restaurant::class, $user->restaurant,
            'AC-12: User->restaurant must be a Restaurant instance');
    }

    /**
     * AC-12: User without restaurant_id returns null for restaurant relationship.
     */
    public function test_user_restaurant_relationship_nullable(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'restaurant_id' => null,
        ]);

        $this->assertNull($user->restaurant,
            'AC-12: User->restaurant must be null when restaurant_id is not set');
    }

    // ========================================================================
    // AC-13: User has belongsTo relationship biker() (nullable)
    // ========================================================================

    /**
     * AC-13: User model has biker() relationship method.
     */
    public function test_user_has_biker_relationship(): void
    {
        $user = new User;
        $this->assertTrue(
            method_exists($user, 'biker'),
            'AC-13: User model must have a biker() method'
        );
    }

    /**
     * AC-13: User with biker_id set returns the related biker.
     */
    public function test_user_biker_relationship_returns_biker(): void
    {
        $biker = Biker::factory()->create();

        $user = User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);

        $this->assertNotNull($user->biker,
            'AC-13: User->biker must not be null when biker_id is set');
        $this->assertEquals($biker->id, $user->biker->id,
            'AC-13: User->biker must return the correct biker');
        $this->assertInstanceOf(Biker::class, $user->biker,
            'AC-13: User->biker must be a Biker instance');
    }

    /**
     * AC-13: User without biker_id returns null for biker relationship.
     */
    public function test_user_biker_relationship_nullable(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'biker_id' => null,
        ]);

        $this->assertNull($user->biker,
            'AC-13: User->biker must be null when biker_id is not set');
    }

    // ========================================================================
    // AC-14: User::isAdmin() returns true only for Admin role
    // ========================================================================

    /**
     * AC-14: isAdmin() returns true for Admin users.
     */
    public function test_is_admin_returns_true_for_admin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertTrue($user->isAdmin(),
            'AC-14: isAdmin() must return true for Admin role');
    }

    /**
     * AC-14: isAdmin() returns false for RestaurantManager users.
     */
    public function test_is_admin_returns_false_for_restaurant_manager(): void
    {
        $user = User::factory()->create(['role' => UserRole::RestaurantManager]);

        $this->assertFalse($user->isAdmin(),
            'AC-14: isAdmin() must return false for RestaurantManager role');
    }

    /**
     * AC-14: isAdmin() returns false for Biker users.
     */
    public function test_is_admin_returns_false_for_biker(): void
    {
        $user = User::factory()->create(['role' => UserRole::Biker]);

        $this->assertFalse($user->isAdmin(),
            'AC-14: isAdmin() must return false for Biker role');
    }

    // ========================================================================
    // AC-15: User::isRestaurantManager() returns true only for RestaurantManager
    // ========================================================================

    /**
     * AC-15: isRestaurantManager() returns true for RestaurantManager users.
     */
    public function test_is_restaurant_manager_returns_true_for_restaurant_manager(): void
    {
        $user = User::factory()->create(['role' => UserRole::RestaurantManager]);

        $this->assertTrue($user->isRestaurantManager(),
            'AC-15: isRestaurantManager() must return true for RestaurantManager role');
    }

    /**
     * AC-15: isRestaurantManager() returns false for Admin users.
     */
    public function test_is_restaurant_manager_returns_false_for_admin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertFalse($user->isRestaurantManager(),
            'AC-15: isRestaurantManager() must return false for Admin role');
    }

    /**
     * AC-15: isRestaurantManager() returns false for Biker users.
     */
    public function test_is_restaurant_manager_returns_false_for_biker(): void
    {
        $user = User::factory()->create(['role' => UserRole::Biker]);

        $this->assertFalse($user->isRestaurantManager(),
            'AC-15: isRestaurantManager() must return false for Biker role');
    }

    // ========================================================================
    // AC-16: User::isBiker() returns true only for Biker role
    // ========================================================================

    /**
     * AC-16: isBiker() returns true for Biker users.
     */
    public function test_is_biker_returns_true_for_biker(): void
    {
        $user = User::factory()->create(['role' => UserRole::Biker]);

        $this->assertTrue($user->isBiker(),
            'AC-16: isBiker() must return true for Biker role');
    }

    /**
     * AC-16: isBiker() returns false for Admin users.
     */
    public function test_is_biker_returns_false_for_admin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertFalse($user->isBiker(),
            'AC-16: isBiker() must return false for Admin role');
    }

    /**
     * AC-16: isBiker() returns false for RestaurantManager users.
     */
    public function test_is_biker_returns_false_for_restaurant_manager(): void
    {
        $user = User::factory()->create(['role' => UserRole::RestaurantManager]);

        $this->assertFalse($user->isBiker(),
            'AC-16: isBiker() must return false for RestaurantManager role');
    }

    // ========================================================================
    // Schema / Migration tests: AC-01 through AC-08
    // ========================================================================

    /**
     * AC-01: users table has phone column (VARCHAR(20), nullable, unique).
     */
    public function test_users_table_has_phone_column(): void
    {
        $user = User::factory()->create([
            'phone' => '5511999999999',
        ]);

        $this->assertEquals('5511999999999', $user->fresh()->phone,
            'AC-01: users table must have a phone column');
    }

    /**
     * AC-01: phone column is nullable.
     */
    public function test_users_phone_is_nullable(): void
    {
        $user = User::factory()->create([
            'phone' => null,
        ]);

        $this->assertNull($user->fresh()->phone,
            'AC-01: phone column must be nullable');
    }

    /**
     * AC-01: phone column has unique constraint.
     */
    public function test_users_phone_is_unique(): void
    {
        User::factory()->create(['phone' => '5511999999999']);

        $this->expectException(QueryException::class);

        User::factory()->create(['phone' => '5511999999999']);
    }

    /**
     * AC-02: users table has phone_verified_at column (timestamp, nullable).
     */
    public function test_users_table_has_phone_verified_at_column(): void
    {
        $user = User::factory()->create([
            'phone_verified_at' => now(),
        ]);

        $this->assertNotNull($user->fresh()->phone_verified_at,
            'AC-02: users table must have a phone_verified_at column');
    }

    /**
     * AC-02: phone_verified_at is nullable.
     */
    public function test_users_phone_verified_at_is_nullable(): void
    {
        $user = User::factory()->create([
            'phone_verified_at' => null,
        ]);

        $this->assertNull($user->fresh()->phone_verified_at,
            'AC-02: phone_verified_at must be nullable');
    }

    /**
     * AC-03: users table has role column (NOT NULL, default 'admin').
     */
    public function test_users_table_has_role_column_with_default(): void
    {
        $user = User::factory()->create();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->role,
            'AC-03: role column must have a default value');
        $this->assertEquals(UserRole::Admin, $fresh->role,
            'AC-03: role column must default to UserRole::Admin');
    }

    /**
     * AC-04: users table has restaurant_id column (nullable FK → restaurants).
     */
    public function test_users_table_has_restaurant_id_column(): void
    {
        $restaurant = Restaurant::factory()->create();

        $user = User::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);

        $this->assertEquals($restaurant->id, $user->fresh()->restaurant_id,
            'AC-04: users table must have a restaurant_id column');
    }

    /**
     * AC-04: restaurant_id is nullable.
     */
    public function test_users_restaurant_id_is_nullable(): void
    {
        $user = User::factory()->create([
            'restaurant_id' => null,
        ]);

        $this->assertNull($user->fresh()->restaurant_id,
            'AC-04: restaurant_id must be nullable');
    }

    /**
     * AC-05: users table has biker_id column (nullable FK → bikers).
     */
    public function test_users_table_has_biker_id_column(): void
    {
        $biker = Biker::factory()->create();

        $user = User::factory()->create([
            'biker_id' => $biker->id,
        ]);

        $this->assertEquals($biker->id, $user->fresh()->biker_id,
            'AC-05: users table must have a biker_id column');
    }

    /**
     * AC-05: biker_id is nullable.
     */
    public function test_users_biker_id_is_nullable(): void
    {
        $user = User::factory()->create([
            'biker_id' => null,
        ]);

        $this->assertNull($user->fresh()->biker_id,
            'AC-05: biker_id must be nullable');
    }

    /**
     * AC-06: email column is nullable on users table.
     */
    public function test_users_email_is_nullable(): void
    {
        $user = User::factory()->create([
            'email' => null,
        ]);

        $this->assertNull($user->fresh()->email,
            'AC-06: email column must be nullable');
    }

    /**
     * AC-06: password column is nullable on users table.
     */
    public function test_users_password_is_nullable(): void
    {
        $user = User::factory()->create([
            'password' => null,
        ]);

        // Password is hashed cast, so we verify the raw DB value
        $this->assertNull($user->fresh()->password,
            'AC-06: password column must be nullable');
    }

    /**
     * AC-07: shifts.created_by has FK constraint to users.id.
     */
    public function test_shifts_created_by_references_users(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();

        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
            'created_by' => $user->id,
        ]);

        $this->assertEquals($user->id, $shift->fresh()->created_by,
            'AC-07: shifts.created_by must reference users.id');
    }

    /**
     * AC-07: shifts.created_by with invalid user_id violates FK constraint.
     */
    public function test_shifts_created_by_fk_constraint_enforced(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->expectException(QueryException::class);

        Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
            'created_by' => 999999,
        ]);
    }

    /**
     * AC-07: shifts.created_by is nullable (existing data compatibility).
     */
    public function test_shifts_created_by_is_nullable(): void
    {
        $restaurant = Restaurant::factory()->create();

        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'draft',
            'restaurant_rate' => '15.00',
            'created_by' => null,
        ]);

        $this->assertNull($shift->fresh()->created_by,
            'AC-07: shifts.created_by must remain nullable');
    }

    // ========================================================================
    // Factory State Tests
    // ========================================================================

    /**
     * User factory admin() state creates admin user.
     */
    public function test_user_factory_admin_state(): void
    {
        $user = User::factory()->create();

        // Default state should be admin
        $this->assertEquals(UserRole::Admin, $user->role,
            'User factory default state should produce an Admin user');
    }

    /**
     * User factory with phone number creates user with phone.
     */
    public function test_user_factory_with_phone(): void
    {
        $user = User::factory()->create([
            'phone' => '5511987654321',
        ]);

        $this->assertEquals('5511987654321', $user->phone);
    }
}
