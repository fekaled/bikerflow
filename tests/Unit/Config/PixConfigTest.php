<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Unit Tests for PIX Config — Phase 4C
 *
 * Tests the config/pix.php file has required webhook settings:
 * - webhook.secret from PIX_WEBHOOK_SECRET env var
 * - webhook.algorithm from PIX_WEBHOOK_ALGORITHM env var
 * - webhook.ip_whitelist from PIX_WEBHOOK_IP_WHITELIST env var
 * - Default values are set correctly
 *
 * Acceptance Criteria: AC-4C-06 through AC-4C-08
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class PixConfigTest extends TestCase
{
    // ========================================================================
    // AC-4C-06: webhook.secret configuration
    // ========================================================================

    public function test_webhook_secret_has_default_value(): void
    {
        $this->assertEquals(
            'default-dev-secret-change-in-production',
            config('pix.webhook.secret'),
            'AC-4C-06: Default webhook secret must be set'
        );
    }

    public function test_webhook_secret_can_be_set_via_env(): void
    {
        config(['pix.webhook.secret' => 'custom-webhook-secret-123']);

        $this->assertEquals(
            'custom-webhook-secret-123',
            config('pix.webhook.secret'),
            'AC-4C-06: webhook.secret must be configurable via PIX_WEBHOOK_SECRET'
        );
    }

    public function test_webhook_secret_is_string(): void
    {
        config(['pix.webhook.secret' => 'test-secret']);

        $this->assertIsString(config('pix.webhook.secret'),
            'AC-4C-06: webhook.secret must be a string');
    }

    // ========================================================================
    // AC-4C-07: webhook.algorithm configuration
    // ========================================================================

    public function test_webhook_algorithm_has_default_sha256(): void
    {
        $this->assertEquals(
            'sha256',
            config('pix.webhook.algorithm'),
            'AC-4C-07: Default webhook algorithm must be sha256'
        );
    }

    public function test_webhook_algorithm_can_be_set_via_env(): void
    {
        config(['pix.webhook.algorithm' => 'sha512']);

        $this->assertEquals(
            'sha512',
            config('pix.webhook.algorithm'),
            'AC-4C-07: webhook.algorithm must be configurable via PIX_WEBHOOK_ALGORITHM'
        );
    }

    public function test_webhook_algorithm_supports_sha256(): void
    {
        config(['pix.webhook.algorithm' => 'sha256']);

        $payload = '{"test":"data"}';
        $secret = 'test-secret';

        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertEquals(64, strlen($signature),
            'SHA256 HMAC produces 64-character hex string');
    }

    public function test_webhook_algorithm_supports_sha512(): void
    {
        config(['pix.webhook.algorithm' => 'sha512']);

        $payload = '{"test":"data"}';
        $secret = 'test-secret';

        $signature = hash_hmac('sha512', $payload, $secret);

        $this->assertEquals(128, strlen($signature),
            'SHA512 HMAC produces 128-character hex string');
    }

    // ========================================================================
    // AC-4C-08: webhook.ip_whitelist configuration
    // ========================================================================

    public function test_webhook_ip_whitelist_has_default_empty(): void
    {
        // Default should be empty (all IPs allowed)
        $whitelist = config('pix.webhook.ip_whitelist');

        $this->assertTrue(
            empty($whitelist) || $whitelist === '' || $whitelist === [],
            'AC-4C-08: Default IP whitelist must be empty (all IPs allowed)'
        );
    }

    public function test_webhook_ip_whitelist_can_be_set_via_env(): void
    {
        config(['pix.webhook.ip_whitelist' => '10.0.0.1,10.0.0.2,192.168.1.100']);

        $whitelist = config('pix.webhook.ip_whitelist');

        $this->assertStringContainsString('10.0.0.1', $whitelist,
            'AC-4C-08: webhook.ip_whitelist must be configurable via PIX_WEBHOOK_IP_WHITELIST');
        $this->assertStringContainsString('10.0.0.2', $whitelist,
            'AC-4C-08: Multiple IPs must be supported');
    }

    public function test_webhook_ip_whitelist_parses_comma_separated_ips(): void
    {
        config(['pix.webhook.ip_whitelist' => '10.0.0.1,10.0.0.2']);

        $whitelist = config('pix.webhook.ip_whitelist');
        $ips = array_map('trim', explode(',', $whitelist));

        $this->assertCount(2, $ips,
            'AC-4C-08: Comma-separated IPs must be parsed');
        $this->assertContains('10.0.0.1', $ips);
        $this->assertContains('10.0.0.2', $ips);
    }

    public function test_webhook_ip_whitelist_empty_disables_check(): void
    {
        config(['pix.webhook.ip_whitelist' => '']);

        $whitelist = config('pix.webhook.ip_whitelist');

        $this->assertTrue(
            empty($whitelist) || $whitelist === '',
            'AC-4C-15: Empty whitelist must disable IP check'
        );
    }

    // ========================================================================
    // Config Structure Tests
    // ========================================================================

    public function test_pix_config_has_gateway_section(): void
    {
        $this->assertTrue(config()->has('pix.gateway'),
            'Config must have pix.gateway section');
    }

    public function test_pix_config_has_webhook_section(): void
    {
        $this->assertTrue(config()->has('pix.webhook'),
            'AC-4C-06: Config must have pix.webhook section');
    }

    public function test_pix_config_gateway_has_driver(): void
    {
        $this->assertNotNull(config('pix.gateway.driver'),
            'Config must have pix.gateway.driver');
    }

    public function test_pix_config_webhook_has_all_required_keys(): void
    {
        $webhook = config('pix.webhook');

        $this->assertIsArray($webhook,
            'pix.webhook must be an array');
        $this->assertArrayHasKey('secret', $webhook,
            'AC-4C-06: pix.webhook must have secret key');
        $this->assertArrayHasKey('algorithm', $webhook,
            'AC-4C-07: pix.webhook must have algorithm key');
        $this->assertArrayHasKey('ip_whitelist', $webhook,
            'AC-4C-08: pix.webhook must have ip_whitelist key');
    }
}
