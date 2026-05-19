<?php

namespace Tests\Feature\Controllers\Admin;

use App\Enums\PaymentAuditAction;
use App\Enums\UserRole;
use App\Models\Biker;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Models\ShiftBiker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Tests for PixKeyController (Admin) — Phase 4A
 *
 * Tests the admin-facing PIX key management endpoints:
 * - GET /admin/bikers/{biker}/pix-keys — list PIX keys (AC-4A-26, AC-4A-27)
 * - POST /admin/pix-keys/{pixKey}/verify — verify a key (AC-4A-28 through AC-4A-30)
 * - POST /admin/pix-keys/{pixKey}/unverify — unverify a key (AC-4A-31 through AC-4A-33)
 * - View rendering (AC-4A-34 through AC-4A-38)
 * - Auth/role middleware protection
 *
 * Business Rules: BR-02 (PIX Verification)
 *
 * @see docs/plans/phase-4a-pix-gateway-key-verification.md
 */
class PixKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nonAdmin;

    private Biker $biker;

    private PixKey $unverifiedKey;

    private PixKey $verifiedKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->nonAdmin = User::factory()->create([
            'role' => UserRole::RestaurantManager,
        ]);

        $this->biker = Biker::factory()->create([
            'name' => 'João da Silva',
            'phone' => '11999999999',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);

        $this->unverifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => '12345678901',
            'is_verified' => false,
            'verified_at' => null,
            'account_holder_name' => null,
        ]);

        $this->verifiedKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'email',
            'key_value' => 'joao@example.com',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Verified Holder Name',
        ]);
    }

    // ========================================================================
    // AC-4A-26: GET /admin/bikers/{biker}/pix-keys returns 200 with view
    // ========================================================================

    public function test_index_returns_200_with_view(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertOk();
        $response->assertViewIs('admin.bikers.pix-keys');
    }

    public function test_index_view_displays_biker_pix_keys(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertViewHas('pixKeys');
        $response->assertSee('12345678901'); // unverified key value
        $response->assertSee('joao@example.com'); // verified key value
    }

    public function test_index_view_displays_biker_name(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('João da Silva');
    }

    public function test_index_view_displays_biker_phone(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('11999999999');
    }

    // ========================================================================
    // AC-4A-34: View displays table with required columns
    // ========================================================================

    public function test_index_view_displays_key_type_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Tipo');
    }

    public function test_index_view_displays_key_value_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Chave');
    }

    public function test_index_view_displays_holder_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Titular');
    }

    public function test_index_view_displays_status_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Status');
    }

    public function test_index_view_displays_verified_at_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Verificado em');
    }

    public function test_index_view_displays_actions_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Ações');
    }

    // ========================================================================
    // AC-4A-35: Unverified keys show "Verificar" button
    // ========================================================================

    public function test_index_shows_verify_button_for_unverified_key(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Verificar');
        $response->assertSee("/admin/pix-keys/{$this->unverifiedKey->id}/verify");
    }

    // ========================================================================
    // AC-4A-36: Verified keys show "Desverificar" button
    // ========================================================================

    public function test_index_shows_unverify_button_for_verified_key(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('Desverificar');
        $response->assertSee("/admin/pix-keys/{$this->verifiedKey->id}/unverify");
    }

    // ========================================================================
    // AC-4A-37: View includes CSRF token on all forms
    // ========================================================================

    public function test_index_includes_csrf_token(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $content = $response->getContent();
        $this->assertStringContainsString(
            'name="_token"',
            $content,
            'All forms must include CSRF token (AC-4A-37)',
        );
    }

    // ========================================================================
    // AC-4A-38: View shows biker name and phone in header
    // ========================================================================

    public function test_index_shows_biker_name_in_header(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('João da Silva');
    }

    public function test_index_shows_biker_phone_in_header(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertSee('11999999999');
    }

    // ========================================================================
    // AC-4A-27: GET /admin/bikers/{biker}/pix-keys — Auth & Role protection
    // ========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertRedirect('/login');
    }

    public function test_index_requires_admin_role(): void
    {
        $response = $this->actingAs($this->nonAdmin)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertForbidden();
    }

    public function test_index_biker_role_is_forbidden(): void
    {
        $bikerRole = User::factory()->create([
            'role' => UserRole::Biker,
        ]);

        $response = $this->actingAs($bikerRole)
            ->get("/admin/bikers/{$this->biker->id}/pix-keys");

        $response->assertForbidden();
    }

    // ========================================================================
    // AC-4A-28: POST /admin/pix-keys/{pixKey}/verify — success
    // ========================================================================

    public function test_verify_calls_service_and_redirects_with_success(): void
    {
        $response = $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$this->unverifiedKey->id}/verify");

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Verify the key was actually verified
        $this->assertTrue(
            $this->unverifiedKey->fresh()->is_verified,
            'verify endpoint must actually verify the key (AC-4A-28)',
        );
        $this->assertNotNull(
            $this->unverifiedKey->fresh()->account_holder_name,
            'verify endpoint must set account_holder_name (AC-4A-28)',
        );
    }

    public function test_verify_writes_audit_log(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$this->unverifiedKey->id}/verify");

        $this->assertDatabaseCount('payment_audit_logs', 1);
        $log = PaymentAuditLog::first();
        $this->assertEquals(PaymentAuditAction::VerifyPix, $log->action);
    }

    // ========================================================================
    // AC-4A-29: POST verify — error on RuntimeException
    // ========================================================================

    public function test_verify_redirects_with_error_on_already_verified(): void
    {
        $response = $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$this->verifiedKey->id}/verify");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_verify_redirects_with_error_on_gateway_failure(): void
    {
        $failingKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'FAIL_NOT_FOUND',
            'is_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$failingKey->id}/verify");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Key must remain unverified
        $this->assertFalse($failingKey->fresh()->is_verified);
    }

    public function test_verify_redirects_with_error_on_gateway_exception(): void
    {
        $errorKey = PixKey::factory()->create([
            'biker_id' => $this->biker->id,
            'key_type' => 'cpf',
            'key_value' => 'ERROR_TIMEOUT',
            'is_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$errorKey->id}/verify");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertFalse($errorKey->fresh()->is_verified);
    }

    // ========================================================================
    // AC-4A-30: POST verify — Auth & Role protection
    // ========================================================================

    public function test_verify_requires_authentication(): void
    {
        $response = $this->post("/admin/pix-keys/{$this->unverifiedKey->id}/verify");

        $response->assertRedirect('/login');
        $this->assertFalse($this->unverifiedKey->fresh()->is_verified, 'Unauthenticated request must not verify key');
    }

    public function test_verify_requires_admin_role(): void
    {
        $response = $this->actingAs($this->nonAdmin)
            ->post("/admin/pix-keys/{$this->unverifiedKey->id}/verify");

        $response->assertForbidden();
        $this->assertFalse($this->unverifiedKey->fresh()->is_verified, 'Non-admin request must not verify key');
    }

    // ========================================================================
    // AC-4A-31: POST /admin/pix-keys/{pixKey}/unverify — success
    // ========================================================================

    public function test_unverify_calls_service_and_redirects_with_success(): void
    {
        $response = $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$this->verifiedKey->id}/unverify");

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $fresh = $this->verifiedKey->fresh();
        $this->assertFalse($fresh->is_verified, 'unverify endpoint must set is_verified=false (AC-4A-31)');
        $this->assertNull($fresh->verified_at, 'unverify endpoint must clear verified_at (AC-4A-31)');
        $this->assertNull($fresh->account_holder_name, 'unverify endpoint must clear account_holder_name (AC-4A-31)');
    }

    public function test_unverify_writes_audit_log(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$this->verifiedKey->id}/unverify");

        $this->assertDatabaseCount('payment_audit_logs', 1);
        $log = PaymentAuditLog::first();
        $this->assertEquals(PaymentAuditAction::VerifyPix, $log->action);
        $this->assertStringStartsWith('pix-unverify-', $log->transaction_ref);
    }

    // ========================================================================
    // AC-4A-32: POST unverify — error on RuntimeException
    // ========================================================================

    public function test_unverify_redirects_with_error_on_not_verified(): void
    {
        $response = $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$this->unverifiedKey->id}/unverify");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ========================================================================
    // AC-4A-33: POST unverify — Auth & Role protection
    // ========================================================================

    public function test_unverify_requires_authentication(): void
    {
        $response = $this->post("/admin/pix-keys/{$this->verifiedKey->id}/unverify");

        $response->assertRedirect('/login');
        $this->assertTrue($this->verifiedKey->fresh()->is_verified, 'Unauthenticated request must not unverify key');
    }

    public function test_unverify_requires_admin_role(): void
    {
        $response = $this->actingAs($this->nonAdmin)
            ->post("/admin/pix-keys/{$this->verifiedKey->id}/unverify");

        $response->assertForbidden();
        $this->assertTrue($this->verifiedKey->fresh()->is_verified, 'Non-admin request must not unverify key');
    }

    // ========================================================================
    // AC-4A-47: isEligibleForRelease/Retry reflect verification state changes
    // ========================================================================

    public function test_verify_makes_biker_eligible_for_payment_release(): void
    {
        // Use a fresh biker with NO verified keys — isolated from setUp fixtures
        $isolatedBiker = Biker::factory()->create([
            'name' => 'Carlos Test',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $isolatedKey = PixKey::factory()->create([
            'biker_id' => $isolatedBiker->id,
            'key_type' => 'cpf',
            'key_value' => '99988877766',
            'is_verified' => false,
        ]);

        // Link a User account to biker (required for eligibility)
        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $isolatedBiker->id,
        ]);

        $restaurant = Restaurant::factory()->create(['rate_per_trip' => '15.00']);
        $shift = Shift::factory()->started()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '15.00',
        ]);
        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $isolatedBiker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
            'trips_count' => 5,
        ]);
        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '75.00',
            'revenue' => '75.00',
            'status' => 'pending',
        ]);

        // Before verify — biker has no verified key
        $this->assertFalse(
            $payment->fresh()->isEligibleForRelease(),
            'Payment should not be eligible before PIX key is verified (AC-4A-47)',
        );

        // Verify the key via the endpoint
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$isolatedKey->id}/verify");

        // After verify — payment should now be eligible
        $this->assertTrue(
            $payment->fresh()->isEligibleForRelease(),
            'Payment should be eligible after PIX key is verified (AC-4A-47)',
        );
    }

    public function test_unverify_makes_biker_ineligible_for_payment_retry(): void
    {
        // Use a fresh biker with exactly ONE verified key — isolated from setUp
        $isolatedBiker = Biker::factory()->create([
            'name' => 'Pedro Test',
            'rate_per_trip' => '10.00',
            'base_fee' => '25.00',
        ]);
        $isolatedVerifiedKey = PixKey::factory()->create([
            'biker_id' => $isolatedBiker->id,
            'key_type' => 'cpf',
            'key_value' => '55566677788',
            'is_verified' => true,
            'verified_at' => now(),
            'account_holder_name' => 'Test Holder',
        ]);

        // Link a User account to biker (required for eligibility)
        User::factory()->create([
            'role' => UserRole::Biker,
            'biker_id' => $isolatedBiker->id,
        ]);

        $restaurant = Restaurant::factory()->create(['rate_per_trip' => '15.00']);
        $shift = Shift::factory()->started()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_rate' => '15.00',
        ]);
        $shiftBiker = ShiftBiker::factory()->create([
            'shift_id' => $shift->id,
            'biker_id' => $isolatedBiker->id,
            'biker_rate' => '10.00',
            'base_fee' => '25.00',
            'trips_count' => 3,
        ]);
        $payment = Payment::factory()->create([
            'shift_biker_id' => $shiftBiker->id,
            'amount' => '55.00',
            'revenue' => '55.00',
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => 'PIX key invalid',
            'retry_count' => 0,
        ]);

        // Before unverify — payment is eligible for retry (key is verified)
        $this->assertTrue(
            $payment->fresh()->isEligibleForRetry(),
            'Payment should be eligible for retry while key is verified',
        );

        // Unverify the ONLY verified key via the endpoint
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$isolatedVerifiedKey->id}/unverify");

        // After unverify — biker has no verified keys, payment no longer eligible
        $this->assertFalse(
            $payment->fresh()->isEligibleForRetry(),
            'Payment should not be eligible for retry after only verified key is unverifed (AC-4A-47)',
        );
    }

    // ========================================================================
    // Integration: Full verify → unverify → re-verify cycle
    // ========================================================================

    public function test_full_verify_unverify_reverify_cycle(): void
    {
        $key = $this->unverifiedKey;

        // Step 1: Verify
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$key->id}/verify");
        $this->assertTrue($key->fresh()->is_verified);

        // Step 2: Unverify
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$key->id}/unverify");
        $this->assertFalse($key->fresh()->is_verified);
        $this->assertNull($key->fresh()->account_holder_name);

        // Step 3: Re-verify
        $this->actingAs($this->admin)
            ->post("/admin/pix-keys/{$key->id}/verify");
        $this->assertTrue($key->fresh()->is_verified);
        $this->assertNotNull($key->fresh()->account_holder_name);

        // 3 audit logs total: verify, unverify, re-verify
        $this->assertDatabaseCount('payment_audit_logs', 3);
    }

    // ========================================================================
    // Edge Case: Non-existent biker/pixKey returns 404
    // ========================================================================

    public function test_index_returns_404_for_nonexistent_biker(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/bikers/999999/pix-keys');

        $response->assertNotFound();
    }

    public function test_verify_returns_404_for_nonexistent_key(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/pix-keys/999999/verify');

        $response->assertNotFound();
    }

    public function test_unverify_returns_404_for_nonexistent_key(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/pix-keys/999999/unverify');

        $response->assertNotFound();
    }
}
