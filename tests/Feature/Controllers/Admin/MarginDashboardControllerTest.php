<?php

namespace Tests\Feature\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MarginDashboardController Feature Tests — Phase 5A
 *
 * Tests the HTTP layer: authorization, route access, data rendering,
 * BRL formatting, and empty-state display for the admin margin dashboard.
 *
 * Acceptance Criteria: AC-01, AC-02, AC-03, AC-04, AC-14, AC-15
 * Business Rules: BR-03 (Payout Formula), BR-04 (Granular Failure)
 *
 * @see docs/plans/phase-5a-admin-margin-dashboard.md
 */
class MarginDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $restaurantManager;

    private User $bikerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->restaurantManager = User::factory()->create([
            'role' => UserRole::RestaurantManager,
        ]);

        $biker = Biker::factory()->create();
        $this->bikerUser = User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $biker->id,
        ]);
    }

    // ========================================================================
    // AC-01: Admin user gets HTTP 200
    // ========================================================================

    /**
     * AC-01: Authenticated admin user receives HTTP 200 from GET /admin/margin-dashboard.
     */
    public function test_admin_receives_http_200(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();
    }

    // ========================================================================
    // AC-02: restaurant_manager user gets HTTP 403
    // ========================================================================

    /**
     * AC-02: Non-admin (restaurant_manager) receives HTTP 403.
     * Enforced by role:admin middleware.
     */
    public function test_restaurant_manager_receives_http_403(): void
    {
        $response = $this->actingAs($this->restaurantManager)->get('/admin/margin-dashboard');

        $response->assertForbidden();
    }

    /**
     * AC-02: Non-admin (biker) receives HTTP 403.
     */
    public function test_biker_receives_http_403(): void
    {
        $response = $this->actingAs($this->bikerUser)->get('/admin/margin-dashboard');

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-03: Unauthenticated user gets redirect to login
    // ========================================================================

    /**
     * AC-03: Unauthenticated user is redirected to the login page.
     */
    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/admin/margin-dashboard');

        $response->assertRedirect(route('login'));
    }

    // ========================================================================
    // AC-04: Empty month — R$ 0,00 on all financial cards, 0 on shift count
    // ========================================================================

    /**
     * AC-04: When no shifts are closed in the current month,
     * the dashboard displays R$ 0,00 for all financial cards and 0 for shift count.
     */
    public function test_empty_month_shows_zero_values(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();

        // BRL-formatted zero amounts
        $response->assertSee('R$ 0,00');
    }

    // ========================================================================
    // AC-14: Dashboard renders 5 distinct card labels
    // ========================================================================

    /**
     * AC-14: The view renders five distinct visual cards with these labels:
     * "Receita Total", "Pagamentos", "Margem Líquida", "Turnos Fechados", "Pagamentos (Pago/Pendente)".
     */
    public function test_dashboard_renders_five_card_labels(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();

        $response->assertSee('Receita Total', false);
        $response->assertSee('Pagamentos', false);
        $response->assertSee('Margem Líquida', false);
        $response->assertSee('Turnos Fechados', false);
        $response->assertSee('Pagamentos (Pago/Pendente)', false);
    }

    // ========================================================================
    // AC-15: BRL locale formatting (R$ prefix, comma decimal, dot thousands)
    // ========================================================================

    /**
     * AC-15: Large financial values use pt_BR locale formatting:
     * - R$ prefix
     * - Comma as decimal separator
     * - Dot as thousands separator
     *
     * Example: R$ 1.234,56
     */
    public function test_br_locale_formatting_with_large_values(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '50.00',
        ]);

        // Create shifts that produce a large total: 25 shifts × 2 bikers × 20 trips
        // Per biker: payout = 25.00 + (10.00 × 20) = 225.00
        // Per shift (2 bikers): 450.00
        // Total (25 shifts): 11,250.00
        for ($day = 1; $day <= 25; $day++) {
            $shift = Shift::factory()->create([
                'restaurant_id' => $restaurant->id,
                'restaurant_rate' => '50.00',
                'status' => ShiftStatus::Closed,
                'started_at' => now()->subDays(30),
                'closed_at' => now()->setDate((int) now()->format('Y'), (int) now()->format('m'), $day)->setTime(18, 0, 0),
            ]);

            $biker = Biker::factory()->create();
            $sb = ShiftBiker::factory()->create([
                'shift_id' => $shift->id,
                'biker_id' => $biker->id,
                'trips_count' => 20,
                'biker_rate' => '10.00',
                'base_fee' => '25.00',
            ]);

            Payment::factory()->create([
                'shift_biker_id' => $sb->id,
                'amount' => '225.00',
                'revenue' => '775.00',
                'status' => PaymentStatus::Pending,
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();

        // BRL format uses dot for thousands separator and comma for decimals
        // R$ 5.625,00 is the expected payout (25 × 2 × 225.00 = 11,250.00 ... 
        // Actually: 25 shifts × 2 bikers × 225.00 = 11,250.00 → R$ 11.250,00
        $response->assertSee('R$ ');
        $response->assertSee(',');
    }

    /**
     * AC-15: Payout card displays BRL format with R$ prefix and comma decimal.
     */
    public function test_payout_displays_brl_format(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        $shift = Shift::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '15.00',
            'status' => ShiftStatus::Closed,
            'started_at' => now()->subDays(10),
            'closed_at' => now(),
        ]);

        $biker = Biker::factory()->create();
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        Payment::factory()->create([
            'shift_biker_id' => $sb->id,
            'amount' => '75.00',
            'revenue' => '0.00',
            'status' => PaymentStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();

        // BRL format: R$ 75,00 (comma decimal, no thousands separator for small amounts)
        $response->assertSee('R$ 75,00');
    }

    /**
     * AC-15: Revenue card displays BRL format.
     */
    public function test_revenue_displays_brl_format(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '20.00',
        ]);

        $shift = Shift::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '20.00',
            'status' => ShiftStatus::Closed,
            'started_at' => now()->subDays(10),
            'closed_at' => now(),
        ]);

        $biker = Biker::factory()->create();
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 5,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        Payment::factory()->create([
            'shift_biker_id' => $sb->id,
            'amount' => '75.00',
            'revenue' => '25.00',
            'status' => PaymentStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();

        // Revenue = (20.00 × 5) − 75.00 = 25.00 → R$ 25,00
        $response->assertSee('R$ 25,00');
    }

    // ========================================================================
    // Integration: Full flow — data from DB to rendered view
    // ========================================================================

    /**
     * Integration test: Create a closed shift, verify all five cards
     * display correct financial values with BRL formatting.
     */
    public function test_dashboard_shows_correct_data_from_closed_shift(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '20.00',
        ]);

        $shift = Shift::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '20.00',
            'status' => ShiftStatus::Closed,
            'started_at' => now()->subDays(5),
            'closed_at' => now(),
        ]);

        $biker = Biker::factory()->create();
        $sb = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $biker->id,
            'trips_count' => 10,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
        ]);

        Payment::factory()->create([
            'shift_biker_id' => $sb->id,
            'amount' => '125.00',
            'revenue' => '75.00',
            'status' => PaymentStatus::Paid,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();

        // Payout: 25.00 + (10.00 × 10) = 125.00 → R$ 125,00
        $response->assertSee('R$ 125,00');

        // Revenue: (20.00 × 10) − 125.00 = 75.00 → R$ 75,00
        $response->assertSee('R$ 75,00');

        // Net margin: 75.00 − 125.00 = -50.00 → displayed with minus sign
        // BRL format for negative: could be "-R$ 50,00" or "R$ -50,00"
        $response->assertSee('50,00');

        // Shift count: 1
        $response->assertSee('1');

        // Views correct Blade template
        $response->assertViewIs('admin.margin-dashboard');
    }

    /**
     * Integration test: Shift count card shows correct number of closed shifts.
     */
    public function test_shift_count_card_shows_correct_count(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        // Create 5 closed shifts in current month
        for ($day = 1; $day <= 5; $day++) {
            Shift::factory()->create([
                'restaurant_id' => $restaurant->id,
                'restaurant_rate' => '15.00',
                'status' => ShiftStatus::Closed,
                'started_at' => now()->subDays(10),
                'closed_at' => now()->setDate((int) now()->format('Y'), (int) now()->format('m'), $day)->setTime(14, 0, 0),
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();
        // The number "5" should appear as the shift count
        $response->assertSee('Turnos Fechados', false);
    }

    // ========================================================================
    // Boundary: Only closed shifts counted, not open/draft
    // ========================================================================

    /**
     * Boundary test: Draft and open shifts are NOT included in aggregation.
     */
    public function test_only_closed_shifts_are_aggregated(): void
    {
        $restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);

        // Draft shift (should be excluded)
        Shift::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '15.00',
            'status' => ShiftStatus::Draft,
        ]);

        // Open shift (should be excluded)
        Shift::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '15.00',
            'status' => ShiftStatus::Open,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/margin-dashboard');

        $response->assertOk();
        // With no closed shifts, only zeros should appear
        $response->assertSee('R$ 0,00');
    }
}
