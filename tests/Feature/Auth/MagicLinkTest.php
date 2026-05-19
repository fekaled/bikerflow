<?php

namespace Tests\Feature\Auth;

use App\Contracts\WhatsappServiceInterface;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\WhatsappLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Magic Link Authentication Tests
 *
 * Validates the phone-based magic link authentication flow.
 *
 * Acceptance Criteria: AC-17 through AC-25, AC-44 through AC-46
 * Business Rules: Session security, user enumeration prevention
 */
class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->create([
            'phone' => '5511999999999',
            'role' => UserRole::Admin,
        ]);
    }

    // ========================================================================
    // AC-17: GET /login shows a form with a phone number input field
    // ========================================================================

    /**
     * AC-17: GET /login returns 200 and shows the login form.
     */
    public function test_login_page_returns_200(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    /**
     * AC-17: GET /login contains a phone number input field.
     */
    public function test_login_page_has_phone_input(): void
    {
        $response = $this->get('/login');

        $response->assertSee('phone');
    }

    /**
     * AC-17: GET /login is accessible to guest users only.
     */
    public function test_login_page_redirects_authenticated_users(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/login');

        $response->assertRedirect('/dashboard');
    }

    // ========================================================================
    // AC-18: POST /login with registered phone creates signed URL
    // ========================================================================

    /**
     * AC-18: POST /login with a registered phone dispatches magic link.
     */
    public function test_send_magic_link_with_registered_phone(): void
    {
        Log::shouldReceive('info')->once(); // WhatsappLogService logs

        $response = $this->post('/login', [
            'phone' => '5511999999999',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    /**
     * AC-18: Signed URL is dispatched via WhatsappServiceInterface.
     */
    public function test_magic_link_uses_whatsapp_service(): void
    {
        $whatsappMock = $this->createMock(WhatsappServiceInterface::class);
        $whatsappMock->expects($this->once())
            ->method('sendMagicLink')
            ->with(
                $this->equalTo('5511999999999'),
                $this->callback(fn ($url) => str_contains($url, '/auth/magic-link/verify/'))
            );

        $this->app->instance(WhatsappServiceInterface::class, $whatsappMock);

        $this->post('/login', [
            'phone' => '5511999999999',
        ]);
    }

    /**
     * AC-18: Signed URL has 15-minute expiry.
     */
    public function test_magic_link_signed_url_has_expiry(): void
    {
        $capturedUrl = null;

        $whatsappMock = $this->createMock(WhatsappServiceInterface::class);
        $whatsappMock->expects($this->once())
            ->method('sendMagicLink')
            ->willReturnCallback(function ($phone, $url) use (&$capturedUrl) {
                $capturedUrl = $url;
            });

        $this->app->instance(WhatsappServiceInterface::class, $whatsappMock);

        $this->post('/login', [
            'phone' => '5511999999999',
        ]);

        $this->assertNotNull($capturedUrl, 'A signed URL must be generated');
        $this->assertStringContainsString('expires=', $capturedUrl,
            'AC-18: Signed URL must contain an expiry parameter');
        $this->assertStringContainsString('signature=', $capturedUrl,
            'AC-18: Signed URL must contain a signature parameter');
    }

    // ========================================================================
    // AC-19: POST /login with unregistered phone does NOT reveal existence
    // ========================================================================

    /**
     * AC-19: Unregistered phone returns same success message (no enumeration).
     */
    public function test_send_magic_link_with_unregistered_phone_returns_same_message(): void
    {
        // First get the message for a registered phone
        $registeredResponse = $this->post('/login', [
            'phone' => '5511999999999',
        ]);
        $registeredMessage = $registeredResponse->session()->get('status');

        // Now test with an unregistered phone
        $unregisteredResponse = $this->post('/login', [
            'phone' => '5511888888888',
        ]);
        $unregisteredMessage = $unregisteredResponse->session()->get('status');

        $this->assertEquals($registeredMessage, $unregisteredMessage,
            'AC-19: Response message must be identical for registered and unregistered phones');
    }

    /**
     * AC-19: Unregistered phone does NOT call WhatsappService.
     */
    public function test_unregistered_phone_does_not_dispatch_whatsapp(): void
    {
        $whatsappMock = $this->createMock(WhatsappServiceInterface::class);
        $whatsappMock->expects($this->never())->method('sendMagicLink');

        $this->app->instance(WhatsappServiceInterface::class, $whatsappMock);

        $this->post('/login', [
            'phone' => '5511888888888',
        ]);
    }

    /**
     * POST /login validates phone is required.
     */
    public function test_send_magic_link_validates_phone_required(): void
    {
        $response = $this->post('/login', [
            'phone' => '',
        ]);

        $response->assertSessionHasErrors('phone');
    }

    /**
     * POST /login validates phone max length of 20.
     */
    public function test_send_magic_link_validates_phone_max_length(): void
    {
        $response = $this->post('/login', [
            'phone' => str_repeat('1', 21),
        ]);

        $response->assertSessionHasErrors('phone');
    }

    // ========================================================================
    // AC-20: Signed URL dispatched via WhatsappServiceInterface (log fake)
    // ========================================================================

    /**
     * AC-20: WhatsappServiceInterface is bound in the container.
     */
    public function test_whatsapp_service_interface_is_resolvable(): void
    {
        $service = $this->app->make(WhatsappServiceInterface::class);

        $this->assertInstanceOf(WhatsappServiceInterface::class, $service,
            'AC-20: WhatsappServiceInterface must be bound in the container');
    }

    // ========================================================================
    // AC-21: Valid magic link authenticates user and sets phone_verified_at
    // ========================================================================

    /**
     * AC-21: Valid magic link URL authenticates the user.
     */
    public function test_valid_magic_link_authenticates_user(): void
    {
        $this->assertNull($this->adminUser->phone_verified_at,
            'phone_verified_at should be null before magic link');

        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(15),
            ['user' => $this->adminUser->id, 'hash' => sha1($this->adminUser->phone)]
        );

        $response = $this->get($url);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->adminUser);
    }

    /**
     * AC-21: Valid magic link sets phone_verified_at timestamp.
     */
    public function test_valid_magic_link_sets_phone_verified_at(): void
    {
        $this->assertNull($this->adminUser->phone_verified_at);

        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(15),
            ['user' => $this->adminUser->id, 'hash' => sha1($this->adminUser->phone)]
        );

        $this->get($url);

        $this->assertNotNull($this->adminUser->fresh()->phone_verified_at,
            'AC-21: phone_verified_at must be set after successful magic link verification');
    }

    // ========================================================================
    // AC-22: Expired/invalid signature returns 401
    // ========================================================================

    /**
     * AC-22: Expired magic link returns 401.
     */
    public function test_expired_magic_link_returns_401(): void
    {
        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->subMinutes(16), // Expired 16 minutes ago
            ['user' => $this->adminUser->id, 'hash' => sha1($this->adminUser->phone)]
        );

        $response = $this->get($url);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    /**
     * AC-22: Tampered signature returns 401.
     */
    public function test_tampered_signature_returns_401(): void
    {
        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(15),
            ['user' => $this->adminUser->id, 'hash' => sha1($this->adminUser->phone)]
        );

        // Tamper with the URL
        $tamperedUrl = str_replace('signature=', 'signature=tampered', $url);

        $response = $this->get($tamperedUrl);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    // ========================================================================
    // AC-23: Wrong hash returns 401
    // ========================================================================

    /**
     * AC-23: Magic link with wrong hash returns 401.
     */
    public function test_wrong_hash_returns_401(): void
    {
        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(15),
            ['user' => $this->adminUser->id, 'hash' => 'wrong-hash-value']
        );

        $response = $this->get($url);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    // ========================================================================
    // AC-24: Session is regenerated after authentication
    // ========================================================================

    /**
     * AC-24: Session is regenerated after successful magic link auth.
     */
    public function test_session_regenerated_after_magic_link_auth(): void
    {
        // Start a session first
        $this->withSession(['_token' => 'old-token']);

        $oldSessionId = session()->getId();

        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(15),
            ['user' => $this->adminUser->id, 'hash' => sha1($this->adminUser->phone)]
        );

        $this->get($url);

        // After regeneration, session ID should have changed
        $newSessionId = session()->getId();
        $this->assertNotEquals($oldSessionId, $newSessionId,
            'AC-24: Session ID must be regenerated after authentication to prevent fixation');
    }

    // ========================================================================
    // AC-25: POST /logout logs out and redirects to /login
    // ========================================================================

    /**
     * AC-25: POST /logout logs out the authenticated user.
     */
    public function test_logout_logs_out_user(): void
    {
        $response = $this->actingAs($this->adminUser)->post('/logout');

        $this->assertGuest();
    }

    /**
     * AC-25: POST /logout redirects to /login.
     */
    public function test_logout_redirects_to_login(): void
    {
        $response = $this->actingAs($this->adminUser)->post('/logout');

        $response->assertRedirect('/login');
    }

    // ========================================================================
    // AC-44: WhatsappServiceInterface contract
    // ========================================================================

    /**
     * AC-44: WhatsappServiceInterface defines sendMagicLink method.
     */
    public function test_whatsapp_service_interface_has_send_magic_link(): void
    {
        $this->assertTrue(
            method_exists(WhatsappServiceInterface::class, 'sendMagicLink'),
            'AC-44: WhatsappServiceInterface must define sendMagicLink() method'
        );
    }

    /**
     * AC-44: WhatsappServiceInterface defines sendMessage method.
     */
    public function test_whatsapp_service_interface_has_send_message(): void
    {
        $this->assertTrue(
            method_exists(WhatsappServiceInterface::class, 'sendMessage'),
            'AC-44: WhatsappServiceInterface must define sendMessage() method'
        );
    }

    // ========================================================================
    // AC-45: WhatsappLogService implements interface and logs
    // ========================================================================

    /**
     * AC-45: WhatsappLogService implements WhatsappServiceInterface.
     */
    public function test_whatsapp_log_service_implements_interface(): void
    {
        $service = $this->app->make(WhatsappServiceInterface::class);

        $this->assertInstanceOf(WhatsappServiceInterface::class, $service,
            'AC-45: The bound service must implement WhatsappServiceInterface');
    }

    /**
     * AC-45: WhatsappLogService sendMagicLink writes to log.
     */
    public function test_whatsapp_log_service_logs_magic_link(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with(\Mockery::pattern('/WhatsApp Fake.*Magic link.*5511999999999/'));

        $service = $this->app->make(WhatsappServiceInterface::class);
        $service->sendMagicLink('5511999999999', 'https://example.com/verify');
    }

    // ========================================================================
    // AC-46: WhatsappServiceInterface bound to WhatsappLogService in container
    // ========================================================================

    /**
     * AC-46: Container binding resolves WhatsappServiceInterface to WhatsappLogService.
     */
    public function test_whatsapp_service_bound_to_log_service(): void
    {
        $service = $this->app->make(WhatsappServiceInterface::class);

        $this->assertInstanceOf(
            WhatsappLogService::class,
            $service,
            'AC-46: WhatsappServiceInterface must be bound to WhatsappLogService'
        );
    }

    // ========================================================================
    // Route existence tests
    // ========================================================================

    /**
     * GET /dashboard is accessible by authenticated users.
     */
    public function test_dashboard_accessible_to_authenticated_users(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertOk();
    }

    /**
     * GET /dashboard redirects guest to /login.
     */
    public function test_dashboard_redirects_guest_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }
}
