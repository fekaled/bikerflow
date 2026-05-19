<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Http\Requests\PixWebhookRequest;
use App\Services\PixWebhookService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4C: Receives POST callbacks from the PIX gateway at /webhooks/pix/status.
 *
 * This controller is UNAUTHENTICATED — security is provided by the
 * VerifyPixWebhookSignature middleware (HMAC signature verification).
 *
 * The controller always returns HTTP 200 to the gateway (except for
 * signature validation failures which are handled by middleware).
 * This prevents infinite retry loops caused by our bugs.
 *
 * Acceptance Criteria: AC-4C-42 through AC-4C-47
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class PixWebhookController extends Controller
{
    public function __construct(
        private readonly PixWebhookService $webhookService
    ) {}

    /**
     * Handle incoming webhook callback from the PIX gateway.
     *
     * AC-4C-42: Returns HTTP 200 with JSON {status, transaction_id} on success
     * AC-4C-43: Returns HTTP 200 with JSON {status: "error"} on unexpected exceptions
     * AC-4C-44: Logs exceptions at error level without exposing internals
     */
    public function handle(PixWebhookRequest $request)
    {
        $payload = $request->validated();

        try {
            $log = $this->webhookService->processWebhook($payload, $request->ip());

            return response()->json([
                'status' => $log->status,
                'transaction_id' => $payload['transaction_id'],
            ], 200);

        } catch (\Exception $e) {
            // AC-4C-44: Log error without exposing internals
            Log::error("Webhook processing failed: {$e->getMessage()}");

            // AC-4C-43: Return 200 to prevent gateway retries on our bugs
            return response()->json([
                'status' => 'error',
                'message' => 'Processing failed',
            ], 200);
        }
    }
}
