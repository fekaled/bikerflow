<?php

namespace Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;

/**
 * UserRole Enum Tests
 *
 * Validates the UserRole backed string enum exists with correct values.
 *
 * Acceptance Criteria: AC-09, AC-10
 * Business Rules: BR-05 (role-based authorization), BR-03 (Admin-only release)
 */
class UserRoleEnumTest extends TestCase
{
    // ========================================================================
    // AC-09: UserRole backed string enum with 3 cases
    // ========================================================================

    /**
     * AC-09: UserRole enum class exists at App\Enums\UserRole.
     */
    public function test_user_role_enum_exists(): void
    {
        $this->assertTrue(
            enum_exists(\App\Enums\UserRole::class),
            'AC-09: App\Enums\UserRole enum must exist'
        );
    }

    /**
     * AC-09: UserRole is a backed string enum.
     */
    public function test_user_role_is_backed_string_enum(): void
    {
        $reflection = new \ReflectionEnum(\App\Enums\UserRole::class);
        $this->assertTrue($reflection->isBacked(),
            'AC-09: UserRole must be a backed enum');

        $backingType = $reflection->getBackingType();
        $this->assertNotNull($backingType,
            'AC-09: UserRole must have a backing type');
        $this->assertSame('string', $backingType->getName(),
            'AC-09: UserRole backing type must be string');
    }

    /**
     * AC-09: UserRole has exactly 3 cases: Admin, RestaurantManager, Biker.
     */
    public function test_user_role_has_three_cases(): void
    {
        $cases = \App\Enums\UserRole::cases();

        $this->assertCount(3, $cases,
            'AC-09: UserRole must have exactly 3 cases: Admin, RestaurantManager, Biker');
    }

    /**
     * AC-09: Admin case has value 'admin'.
     */
    public function test_user_role_admin_value(): void
    {
        $this->assertSame('admin', \App\Enums\UserRole::Admin->value,
            'AC-09: UserRole::Admin must have value "admin"');
    }

    /**
     * AC-09: RestaurantManager case has value 'restaurant_manager'.
     */
    public function test_user_role_restaurant_manager_value(): void
    {
        $this->assertSame('restaurant_manager', \App\Enums\UserRole::RestaurantManager->value,
            'AC-09: UserRole::RestaurantManager must have value "restaurant_manager"');
    }

    /**
     * AC-09: Biker case has value 'biker'.
     */
    public function test_user_role_biker_value(): void
    {
        $this->assertSame('biker', \App\Enums\UserRole::Biker->value,
            'AC-09: UserRole::Biker must have value "biker"');
    }

    /**
     * AC-09: UserRole::from() works correctly for all valid values.
     */
    public function test_user_role_from_returns_correct_cases(): void
    {
        $this->assertSame(\App\Enums\UserRole::Admin, \App\Enums\UserRole::from('admin'));
        $this->assertSame(\App\Enums\UserRole::RestaurantManager, \App\Enums\UserRole::from('restaurant_manager'));
        $this->assertSame(\App\Enums\UserRole::Biker, \App\Enums\UserRole::from('biker'));
    }

    /**
     * AC-09: UserRole::tryFrom() returns null for invalid values.
     */
    public function test_user_role_try_from_returns_null_for_invalid(): void
    {
        $this->assertNull(\App\Enums\UserRole::tryFrom('superadmin'),
            'AC-09: UserRole::tryFrom("superadmin") must return null');
        $this->assertNull(\App\Enums\UserRole::tryFrom(''),
            'AC-09: UserRole::tryFrom("") must return null');
    }

    // ========================================================================
    // AC-10: UserRole labels() method
    // ========================================================================

    /**
     * AC-10: UserRole has a labels() method returning human-readable labels.
     */
    public function test_user_role_has_labels_method(): void
    {
        $this->assertTrue(
            method_exists(\App\Enums\UserRole::class, 'labels'),
            'AC-10: UserRole must have a labels() method'
        );
    }

    /**
     * AC-10: labels() returns an array with human-readable values for all cases.
     */
    public function test_user_role_labels_returns_readable_values(): void
    {
        $labels = \App\Enums\UserRole::labels();

        $this->assertIsArray($labels, 'AC-10: labels() must return an array');
        $this->assertCount(3, $labels, 'AC-10: labels() must return exactly 3 entries');
        $this->assertArrayHasKey('admin', $labels, 'AC-10: labels() must have "admin" key');
        $this->assertArrayHasKey('restaurant_manager', $labels, 'AC-10: labels() must have "restaurant_manager" key');
        $this->assertArrayHasKey('biker', $labels, 'AC-10: labels() must have "biker" key');
    }
}
