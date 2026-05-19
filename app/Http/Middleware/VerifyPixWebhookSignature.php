<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC signature verification middleware for PIX webhook endpoints.
 *
 * Verifies that incoming webhook requests are signed with the shared secret
 * using HMAC (default: SHA256). Uses timing-safe comparison (hash_equals)
 * to prevent timing attacks.
 *
 * Optional IP allowlist: if configured, only requests from whitelisted IPs
 * are accepted. Empty whitelist (default) disables this check.
 *
 * Acceptance Criteria: AC-4C-09 through AC-4C-15
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class VerifyPixWebhookSignature
{
    /**
     * Handle an incoming webhook request.
     *
     * 1. Check for X-Webhook-Signature header (401 if missing)
     * 2. Compute HMAC over raw request body using configured secret/algorithm
     * 3. Timing-safe comparison via hash_equals (401 if mismatch)
     * 4. Optional IP allowlist check (403 if not authorized)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('pix.webhook.secret');
        $algorithm = config('pix.webhook.algorithm', 'sha256');

        // AC-4C-09: Missing signature header → 401
        $signature = $request->header('X-Webhook-Signature');

        if ($signature === null || $signature === '') {
            Log::warning('Webhook received without signature');

            return new Response(
                json_encode(['error' => 'Missing signature']),
                401,
                ['Content-Type' => 'application/json']
            );
        }

        // AC-4C-13: Compute HMAC over raw body, not parsed JSON
        $payload = $request->getContent();
        $expectedSignature = hash_hmac($algorithm, $payload, $secret);

        // AC-4C-10, AC-4C-12: Timing-safe comparison
        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning('Webhook signature mismatch');

            return new Response(
                json_encode(['error' => 'Invalid signature']),
                401,
                ['Content-Type' => 'application/json']
            );
        }

        // AC-4C-14, AC-4C-15: Optional IP allowlist
        $ipWhitelist = config('pix.webhook.ip_whitelist', '');

        if (! empty($ipWhitelist)) {
            $allowedIps = array_map('trim', explode(',', $ipWhitelist));
            $clientIp = $request->ip();

            if (! in_array($clientIp, $allowedIps, true)) {
                Log::warning("Webhook from unauthorized IP: {$clientIp}");

                return new Response(
                    json_encode(['error' => 'Unauthorized IP']),
                    403,
                    ['Content-Type' => 'application/json']
                );
            }
        }

        return $next($request);
    }
}
