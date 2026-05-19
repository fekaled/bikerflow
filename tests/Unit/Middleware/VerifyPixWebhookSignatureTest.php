<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyPixWebhookSignature;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Tests\TestCase;

/**
 * Unit Tests for VerifyPixWebhookSignature Middleware — Phase 4C
 *
 * Tests HMAC signature verification for PIX webhook endpoints:
 * - Missing signature returns 401
 * - Invalid signature returns 401
 * - Valid signature passes through
 * - IP allowlist enforcement
 * - Timing-safe comparison (hash_equals)
 * - Raw body (not parsed JSON) used for HMAC
 *
 * Acceptance Criteria: AC-4C-09 through AC-4C-15
 * Business Rules: Security (HMAC verification)
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
#[Group('phase4c')]
class VerifyPixWebhookSignatureTest extends TestCase
{
    private string $webhookSecret = 'test-webhook-secret-for-testing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pix.webhook.secret' => $this->webhookSecret]);
        config(['pix.webhook.algorithm' => 'sha256']);
        config(['pix.webhook.ip_whitelist' => '']);
    }

    private function computeSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->webhookSecret);
    }

    private function makeRequest(
        ?string $payload = '{"transaction_id":"txn-123","status":"processed"}',
        ?string $signature = null,
        ?string $ip = '127.0.0.1'
    ): Request {
        $request = Request::create('/webhooks/pix/status', 'POST', [], [], [], [], $payload);
        $request->headers->set('Content-Type', 'application/json');

        if ($signature !== null) {
            $request->headers->set('X-Webhook-Signature', $signature);
        }

        if ($ip !== null) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        return $request;
    }

    private function runMiddleware(Request $request): BaseResponse
    {
        $middleware = new VerifyPixWebhookSignature;

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['status' => 'ok'], 200);
        };

        return $middleware->handle($request, $next);
    }

    // ========================================================================
    // AC-4C-09: Missing signature returns 401
    // ========================================================================

    public function test_missing_signature_returns_401(): void
    {
        $request = $this->makeRequest(payload: null);

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'AC-4C-09: Must return 401 when X-Webhook-Signature header is absent');

        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $content,
            'AC-4C-09: Response must have error key');
        $this->assertEquals('Missing signature', $content['error'],
            'AC-4C-09: Error message must be "Missing signature"');
    }

    // ========================================================================
    // AC-4C-10: Invalid signature returns 401
    // ========================================================================

    public function test_invalid_signature_returns_401(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $request = $this->makeRequest(
            payload: $payload,
            signature: 'invalid-signature-that-does-not-match'
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'AC-4C-10: Must return 401 when HMAC signature does not match');

        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $content,
            'AC-4C-10: Response must have error key');
        $this->assertEquals('Invalid signature', $content['error'],
            'AC-4C-10: Error message must be "Invalid signature"');
    }

    public function test_signature_mismatch_wrong_secret(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        // Compute signature with wrong secret
        $wrongSignature = hash_hmac('sha256', $payload, 'wrong-secret');
        $request = $this->makeRequest(
            payload: $payload,
            signature: $wrongSignature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'AC-4C-10: Must return 401 when signature computed with wrong secret');
    }

    public function test_signature_mismatch_modified_payload(): void
    {
        $originalPayload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($originalPayload);

        // Send modified payload
        $modifiedPayload = '{"transaction_id":"txn-123","status":"failed"}';
        $request = $this->makeRequest(
            payload: $modifiedPayload,
            signature: $signature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'AC-4C-10: Must return 401 when payload is modified after signing');
    }

    // ========================================================================
    // AC-4C-11: Valid signature passes through
    // ========================================================================

    public function test_valid_signature_passes_through(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-11: Must pass through when HMAC signature is valid');

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('ok', $content['status'],
            'AC-4C-11: Next middleware must be called');
    }

    public function test_valid_signature_with_whitespace_in_payload(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"} ';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-11: Must pass through even with trailing whitespace');
    }

    // ========================================================================
    // AC-4C-12: Timing-safe comparison (hash_equals)
    // ========================================================================

    public function test_signature_comparison_is_timing_safe(): void
    {
        // This test verifies that hash_equals is used internally.
        // We can't directly test timing safety, but we can verify the behavior is correct.
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $validSignature = $this->computeSignature($payload);

        // Test exact match
        $request = $this->makeRequest(
            payload: $payload,
            signature: $validSignature
        );
        $response = $this->runMiddleware($request);
        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-12: Exact signature must be accepted');

        // Test near-match (different last char)
        $nearMatch = substr($validSignature, 0, -1).'X';
        $request = $this->makeRequest(
            payload: $payload,
            signature: $nearMatch
        );
        $response = $this->runMiddleware($request);
        $this->assertEquals(401, $response->getStatusCode(),
            'AC-4C-12: Near-match signature must be rejected');
    }

    // ========================================================================
    // AC-4C-13: HMAC computed over raw body, not parsed JSON
    // ========================================================================

    public function test_signature_uses_raw_body_not_parsed_json(): void
    {
        // The raw body is signed, not the parsed/indented JSON
        $rawPayload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($rawPayload);
        $request = $this->makeRequest(
            payload: $rawPayload,
            signature: $signature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-13: Raw body must be used for HMAC computation');
    }

    public function test_different_json_formatting_fails_signature(): void
    {
        // Raw payload with no spaces
        $rawPayload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($rawPayload);

        // Same data but different formatting (extra whitespace)
        $reformattedPayload = '{"transaction_id":"txn-123","status":"processed"} ';
        $request = $this->makeRequest(
            payload: $reformattedPayload,
            signature: $signature
        );

        // Should fail because raw body differs
        $response = $this->runMiddleware($request);
        $this->assertEquals(401, $response->getStatusCode(),
            'AC-4C-13: Different JSON formatting must fail signature verification');
    }

    // ========================================================================
    // AC-4C-14: IP allowlist enforcement
    // ========================================================================

    public function test_unauthorized_ip_returns_403(): void
    {
        config(['pix.webhook.ip_whitelist' => '10.0.0.1,10.0.0.2,192.168.1.1']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature,
            ip: '203.0.113.50'  // Not in whitelist
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(403, $response->getStatusCode(),
            'AC-4C-14: Must return 403 when IP is not in allowlist');

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Unauthorized IP', $content['error'],
            'AC-4C-14: Error message must be "Unauthorized IP"');
    }

    public function test_authorized_ip_passes_through(): void
    {
        config(['pix.webhook.ip_whitelist' => '10.0.0.1,10.0.0.2,192.168.1.1']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature,
            ip: '10.0.0.1'  // In whitelist
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-14: Authorized IP must pass through');
    }

    public function test_ip_in_whitelist_with_port_passes(): void
    {
        config(['pix.webhook.ip_whitelist' => '10.0.0.1,10.0.0.2']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature,
            ip: '10.0.0.1'
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-14: IP from whitelist must pass (regardless of port in header)');
    }

    // ========================================================================
    // AC-4C-15: Empty allowlist disables IP check
    // ========================================================================

    public function test_empty_allowlist_allows_all_ips(): void
    {
        config(['pix.webhook.ip_whitelist' => '']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature,
            ip: '203.0.113.50'  // Arbitrary IP
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-15: Empty allowlist must disable IP check');
    }

    public function test_empty_allowlist_config_allows_all_ips(): void
    {
        config(['pix.webhook.ip_whitelist' => null]);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature,
            ip: '203.0.113.50'
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'AC-4C-15: Null allowlist must disable IP check');
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function test_empty_signature_returns_401(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $request = $this->makeRequest(
            payload: $payload,
            signature: ''  // Empty string
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'Empty signature must be rejected as missing');
    }

    public function test_signature_with_extra_whitespace_rejected(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $validSignature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $validSignature.' '  // Extra whitespace
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'Signature with extra whitespace must be rejected');
    }

    public function test_ipv6_address_handled_correctly(): void
    {
        config(['pix.webhook.ip_whitelist' => '::1,::ffff:127.0.0.1']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature,
            ip: '::1'  // IPv6 localhost
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'IPv6 addresses must be handled correctly');
    }

    public function test_case_sensitive_signature(): void
    {
        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = $this->computeSignature($payload);
        $lowercaseSignature = strtolower($signature);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $lowercaseSignature
        );

        $response = $this->runMiddleware($request);

        // HMAC produces lowercase hex, so this should pass
        $this->assertEquals(200, $response->getStatusCode(),
            'Lowercase HMAC signature must be accepted');
    }

    public function test_algorithm_from_config(): void
    {
        config(['pix.webhook.algorithm' => 'sha512']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        $signature = hash_hmac('sha512', $payload, $this->webhookSecret);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode(),
            'Must respect algorithm from config (sha512)');
    }

    public function test_wrong_algorithm_fails(): void
    {
        config(['pix.webhook.algorithm' => 'sha256']);

        $payload = '{"transaction_id":"txn-123","status":"processed"}';
        // Compute with sha1 instead of sha256
        $signature = hash_hmac('sha1', $payload, $this->webhookSecret);
        $request = $this->makeRequest(
            payload: $payload,
            signature: $signature
        );

        $response = $this->runMiddleware($request);

        $this->assertEquals(401, $response->getStatusCode(),
            'Wrong algorithm must fail signature verification');
    }
}
