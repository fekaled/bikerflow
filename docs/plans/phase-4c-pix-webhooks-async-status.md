# Plan: Phase 4C — PIX Webhooks & Async Status Updates

**Task ID:** Phase-4C
**Date:** 2026-05-17
**Planner Version:** 1.0
**Complexity:** Complex

---

## 1. Objective

Handle asynchronous PIX gateway callbacks. When the gateway returns `status = "queued"` during payment initiation (Phase 4B), the bank eventually calls back with the final status. This phase creates an unauthenticated webhook endpoint that receives these callbacks, verifies their authenticity via HMAC signature, and updates the corresponding payment status — completing the `processing → paid/failed` lifecycle for async payments.

---

## 2. Source References

### User Stories
- US-04 (partial): "As a Biker, I want to be notified if my PIX payment fails" — the webhook resolving a payment to `failed` is the trigger for future notification infrastructure.

### Business Rules
- **BR-04: Granular Failure** — Webhook processing handles each payment independently. One webhook failure does not affect others.
- **BR-06: Payment Retries** — Webhook-driven status updates must write a unique `PaymentAuditLog` entry, maintaining the complete audit trail alongside release/retry/gateway-attempt entries.

### PRD Sections
- Section 1 (Executive Summary): "integrates with a banking API for bulk PIX payments"
- Section 4 (BR-04): "A failed payment for Biker A does not stop the successful payment of Biker B."
- Section 4 (BR-06): "All 'Retry' actions must be logged as unique transaction attempts to prevent double-billing."

### Tech Doc Sections
- Section 5 (Security & Guardrails): BR-02, BR-04, BR-06

### ADR References
- ADR-001: Core Payout Schema — Payment state machine (`pending → processing → paid/failed`)
- ADR-006 D4: Shift auto-transition gates — `approved → paid` when all payments are paid (webhook must trigger reconciliation)

### Phase 4A Dependency
- `PixGatewayInterface::checkPaymentStatus()` — defined in Phase 4A, used here for optional status confirmation

### Phase 4B Dependency
- `payments.gateway_transaction_id` — the webhook lookup key
- `payments.gateway_status` — updated by webhook to reflect final state
- `PixPaymentService` — webhook reuses the same auto-transition + reconciliation logic

---

## 3. Scope

### In Scope
1. `PixWebhookController` — receives POST callbacks from the gateway at a public endpoint
2. `VerifyPixWebhookSignature` middleware — HMAC signature verification to prevent spoofed callbacks
3. `PixWebhookService` — processes webhook payload: resolves payment by `gateway_transaction_id`, updates status, writes audit log, triggers shift reconciliation
4. `MockPixGateway::checkPaymentStatus()` — extended to support testing webhook scenarios
5. Route: `POST /webhooks/pix/status` — unauthenticated, signature-verified
6. Config: webhook secret, HMAC algorithm, IP allowlist (optional)
7. Idempotent processing — duplicate webhook deliveries (same `gateway_transaction_id` + same status) are no-ops, not errors
8. Unit tests for `PixWebhookService`, `VerifyPixWebhookSignature` middleware
9. Feature tests for `PixWebhookController` — success, failure, duplicate, invalid signature, payment not found
10. Artisan command `pix:webhook:verify {gatewayTransactionId}` — manual status check tool for Admins

### Out of Scope
1. PIX key verification — Phase 4A
2. Payment initiation (gateway call) — Phase 4B
3. Real gateway webhook payload format — mock only; real provider payload parsing is future work
4. Biker notification on failure (US-04) — the webhook resolves the status, but sending notifications (email/push) is Phase 5
5. Webhook retry/queuing — if processing fails, the gateway will retry per its own policy; we handle each delivery idempotently
6. Queue-based webhook processing — synchronous processing for MVP; queue jobs are a future optimization
7. Admin UI for webhook delivery logs — future enhancement

### Open Questions
None. All architectural decisions are covered by existing ADRs and Phase 4A/4B plans.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Not relevant to webhook processing |
| BR-02 PIX Verification | No | Verification already done at release time; webhook does not re-check |
| BR-03 Manual Release | **Yes** | Webhook is NOT a manual release — it resolves an already-released payment. It does not trigger new releases. |
| BR-04 Granular Failure | **Yes** | Each webhook payload contains one payment status update. Batch webhooks (if any) process each payment independently. |
| BR-05 Last Minute Biker | No | Not relevant to webhook processing |
| BR-06 Payment Retries | **Yes** | Every webhook-driven status update writes a unique `PaymentAuditLog` entry. Duplicate webhooks for the same status are idempotent (no duplicate audit log). |

---

## 5. Schema Changes

### New Tables

```
pix_webhook_logs
├── id                    BIGINT UNSIGNED PK     — Auto-increment
├── gateway_transaction_id  STRING(255)          — The transaction ID from the gateway payload
├── payload               JSON NULLABLE          — Full webhook payload (for debugging/replay)
├── status                STRING(20)             — Processing result: "processed", "duplicate", "ignored", "error"
├── error_message         TEXT NULLABLE           — Error details if processing failed
├── ip_address            STRING(45) NULLABLE    — Source IP of the webhook call
├── received_at           TIMESTAMP              — When the webhook was received
└── timestamps
```

> This table is **operational/debugging infrastructure**, not a core business entity. It records every webhook delivery for auditability and troubleshooting.

### Modified Tables

No modifications to existing tables.

### Indexes

- `idx_webhook_logs_gateway_txn_id` on `pix_webhook_logs(gateway_transaction_id)` — lookup by transaction ID for debugging
- `idx_webhook_logs_received_at` on `pix_webhook_logs(received_at)` — time-based queries for monitoring
- `idx_webhook_logs_status` on `pix_webhook_logs(status)` — filter by processing result

### Financial Column Checklist

No financial columns in this phase.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Migration | `database/migrations/2026_05_17_000003_create_pix_webhook_logs_table.php` | `pix_webhook_logs` table |
| Model | `app/Models/PixWebhookLog.php` | Eloquent model for webhook log |
| Controller | `app/Http/Controllers/Webhook/PixWebhookController.php` | Receives POST callbacks at `/webhooks/pix/status` |
| Middleware | `app/Http/Middleware/VerifyPixWebhookSignature.php` | HMAC signature verification |
| Service | `app/Services/PixWebhookService.php` | Processes webhook payload, updates payment, writes audit. **Delegates reconciliation to `PixPaymentService::reconcileShiftStatus()`** (ADR-006 D4 third path) |
| Request | `app/Http/Requests/PixWebhookRequest.php` | Validates webhook payload structure |
| Command | `app/Console/Commands/VerifyPixPayment.php` | Artisan command for manual status check |
| Config | `config/pix.php` (modify Phase 4A config) | Add webhook section: secret, algorithm, ip_whitelist |
| Test | `tests/Unit/Services/PixWebhookServiceTest.php` | Unit tests for webhook processing logic |
| Test | `tests/Unit/Middleware/VerifyPixWebhookSignatureTest.php` | Unit tests for HMAC verification |
| Test | `tests/Feature/Controllers/PixWebhookControllerTest.php` | Feature tests for webhook endpoint |
| Factory | `database/factories/PixWebhookLogFactory.php` | Factory for testing |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Routes | `routes/web.php` | Add `POST /webhooks/pix/status` route (outside auth middleware) |
| Service | `app/Services/Gateway/MockPixGateway.php` | Extend `checkPaymentStatus()` with deterministic scenarios |
| Bootstrap | `bootstrap/app.php` | Register `VerifyPixWebhookSignature` middleware alias |
| Config | `config/pix.php` | Add `webhook` section with `secret`, `algorithm`, `ip_whitelist` |

---

## 7. Pseudocode

### Webhook Payload Format (Mock)

The mock gateway sends JSON payloads with this structure:

```json
{
    "transaction_id": "mock-txn-123-1700000000",
    "status": "processed",
    "amount": "25.50",
    "pix_key": "11999999999",
    "error_code": null,
    "error_message": null,
    "timestamp": "2026-05-17T15:30:00Z"
}
```

For failure:
```json
{
    "transaction_id": "mock-txn-456-1700000001",
    "status": "failed",
    "amount": "30.00",
    "pix_key": "11999999999",
    "error_code": "ACCOUNT_CLOSED",
    "error_message": "Conta do destinatário encerrada",
    "timestamp": "2026-05-17T15:31:00Z"
}
```

### VerifyPixWebhookSignature Middleware

```
CLASS VerifyPixWebhookSignature:

    FUNCTION handle(request, next):
        secret = config('pix.webhook.secret')
        algorithm = config('pix.webhook.algorithm', 'sha256')

        // Get signature from header
        signature = request.header('X-Webhook-Signature')

        IF signature IS null:
            LOG warning: "Webhook received without signature"
            RETURN response()->json({error: "Missing signature"}, 401)

        // Compute expected signature
        payload = request->getContent()   // Raw body, not parsed JSON
        expectedSignature = hash_hmac(algorithm, payload, secret)

        IF NOT hash_equals(expectedSignature, signature):
            LOG warning: "Webhook signature mismatch"
            RETURN response()->json({error: "Invalid signature"}, 401)

        // Optional: IP allowlist check
        ipWhitelist = config('pix.webhook.ip_whitelist', [])
        IF ipWhitelist IS NOT empty AND request.ip() NOT IN ipWhitelist:
            LOG warning: "Webhook from unauthorized IP: {request.ip()}"
            RETURN response()->json({error: "Unauthorized IP"}, 403)

        RETURN next(request)
```

### PixWebhookService — Core Logic

```
CLASS PixWebhookService:

    FUNCTION __construct(protected PixPaymentService $paymentService)

    FUNCTION processWebhook(payload, ipAddress) -> PixWebhookLog:

        transactionId = payload['transaction_id']
        status = payload['status']
        errorCode = payload['error_code'] ?? null
        errorMessage = payload['error_message'] ?? null

        // Step 1: Find payment by gateway_transaction_id
        payment = Payment::where('gateway_transaction_id', transactionId).first()

        IF payment IS null:
            RETURN createWebhookLog(
                transactionId, payload, "ignored",
                "Payment not found for transaction_id: {transactionId}",
                ipAddress
            )

        // Step 2: Idempotency check — if payment is already in a terminal state
        IF payment.status IS Paid OR payment.status IS Failed:
            existingLog = PaymentAuditLog::where('payment_id', payment.id)
                ->where('payload->webhook_status', status)
                ->where('action', '!=', 'gateway_attempt')
                ->exists()

            IF existingLog:
                RETURN createWebhookLog(
                    transactionId, payload, "duplicate",
                    "Payment already in terminal status: {payment.status}",
                    ipAddress
                )

        // Step 3: Guard — payment must be in processing status
        IF payment.status != Processing:
            RETURN createWebhookLog(
                transactionId, payload, "ignored",
                "Payment not in processing status (current: {payment.status})",
                ipAddress
            )

        // Step 4: Process status update
        IF status == "processed":
            payment.status = Paid
            payment.paid_at = now()
            payment.gateway_status = "processed"
            payment.save()

            PaymentAuditLog.create(
                payment_id = payment.id,
                action = Succeed,
                transaction_ref = "webhook-paid-{payment.id}-{uuid()}",
                payload = {
                    source = "webhook",
                    transaction_id = transactionId,
                    paid_at = payment.paid_at,
                    amount = payment.amount,
                    webhook_ip = ipAddress
                }
            )

            // Reconcile shift status (approved → paid if all paid)
            // Delegates to PixPaymentService::reconcileShiftStatus() to keep all
            // gateway-driven reconciliation in one place (ADR-006 D4, third path)
            paymentService.reconcileShiftStatus(payment.shiftBiker.shift)

        ELSE IF status == "failed":
            payment.status = Failed
            payment.failed_at = now()
            payment.failure_reason = errorMessage ?? "Gateway webhook: {errorCode}"
            payment.gateway_status = "failed"
            payment.save()

            PaymentAuditLog.create(
                payment_id = payment.id,
                action = Fail,
                transaction_ref = "webhook-failed-{payment.id}-{uuid()}",
                error_message = errorMessage,
                payload = {
                    source = "webhook",
                    transaction_id = transactionId,
                    error_code = errorCode,
                    failed_at = payment.failed_at,
                    amount = payment.amount,
                    webhook_ip = ipAddress
                }
            )

            // BR-04: DO NOT touch shift.status

        ELSE:
            // Unknown status — log but don't change payment
            RETURN createWebhookLog(
                transactionId, payload, "ignored",
                "Unknown webhook status: {status}",
                ipAddress
            )

        RETURN createWebhookLog(transactionId, payload, "processed", null, ipAddress)
```

### PixWebhookController

```
CLASS PixWebhookController:

    FUNCTION __construct(protected PixWebhookService $webhookService)

    FUNCTION handle(PixWebhookRequest request):
        payload = request->validated()

        TRY:
            log = webhookService.processWebhook(payload, request->ip())

            RETURN response()->json({
                status: log->status,
                transaction_id: payload['transaction_id']
            }, 200)

        CATCH Exception e:
            LOG error: "Webhook processing failed: {e.message}"

            // Still return 200 to prevent gateway retries on our bugs
            // (Unless it's a signature error, which is handled by middleware)
            RETURN response()->json({
                status: "error",
                message: "Processing failed"
            }, 200)
```

### Manual Verification Command

```
COMMAND pix:webhook:verify {gatewayTransactionId}:
    "Manually check payment status from the gateway"

    payment = Payment::where('gateway_transaction_id', gatewayTransactionId).first()

    IF NOT payment:
        ERROR "Payment not found for transaction: {gatewayTransactionId}"
        RETURN 1

    IF payment.status != Processing:
        INFO "Payment {payment.id} is already {payment.status}. No check needed."
        RETURN 0

    gateway = app(PixGatewayInterface)
    response = gateway.checkPaymentStatus(gatewayTransactionId)

    INFO "Gateway status: {response.status}"
    INFO "Transaction ID: {response.transaction_id}"

    // Uses PixWebhookService::processWebhook() internally to ensure
    // the same audit + reconciliation logic as the webhook endpoint.
    // Alternatively, delegates to PixPaymentService for the state transition
    // and calls paymentService.reconcileShiftStatus() (ADR-006 D4).

    IF response.status == "processed":
        payment.status = Paid
        payment.paid_at = now()
        payment.gateway_status = "processed"
        payment.save()
        PaymentAuditLog.create(...)   // Same as webhook flow
        paymentService.reconcileShiftStatus(payment.shiftBiker.shift)
        INFO "Payment {payment.id} marked as PAID."

    ELSE IF response.status == "failed":
        payment.status = Failed
        payment.failed_at = now()
        payment.failure_reason = response.error_message
        payment.gateway_status = "failed"
        payment.save()
        PaymentAuditLog.create(...)   // Same as webhook flow
        INFO "Payment {payment.id} marked as FAILED: {response.error_message}"

    ELSE:
        INFO "Payment still in processing. Gateway status: {response.status}"
```

### MockPixGateway::checkPaymentStatus() — Extended

```
FUNCTION checkPaymentStatus(transactionId):

    // Deterministic mock based on transaction ID suffix pattern:
    // - Ends with "-sync-paid"    → processed
    // - Ends with "-sync-failed"  → failed
    // - Ends with "-sync-pending" → queued (still processing)
    // - Default                   → processed (optimistic)

    IF transactionId CONTAINS "-sync-failed":
        RETURN PaymentResponse(
            success = true,
            transaction_id = transactionId,
            status = "failed",
            error_code = "RECIPIENT_NOT_FOUND",
            error_message = "Destinatário não encontrado"
        )

    IF transactionId CONTAINS "-sync-pending":
        RETURN PaymentResponse(
            success = true,
            transaction_id = transactionId,
            status = "queued"
        )

    // Default: processed
    RETURN PaymentResponse(
        success = true,
        transaction_id = transactionId,
        status = "processed"
    )
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware |
|--------|-----|-------------------|------|------------|
| POST | `/webhooks/pix/status` | `PixWebhookController@handle` | None (public) | `VerifyPixWebhookSignature` |

> This route is **outside** the `auth` middleware group. The gateway is an external system with no user session.

### Config Additions (config/pix.php)

```
RETURN [
    'gateway' => [
        'driver' => env('PIX_GATEWAY_DRIVER', 'mock'),
        'timeout' => env('PIX_GATEWAY_TIMEOUT', 30),
        'mock' => [
            'holder_name_prefix' => 'MOCK HOLDER for',
        ],
    ],

    // NEW: Phase 4C
    'webhook' => [
        'secret' => env('PIX_WEBHOOK_SECRET', 'default-dev-secret-change-in-production'),
        'algorithm' => env('PIX_WEBHOOK_ALGORITHM', 'sha256'),
        'ip_whitelist' => env('PIX_WEBHOOK_IP_WHITELIST', ''),   // comma-separated, empty = disabled
    ],
]
```

### State Transitions — Webhook-Driven

```
[processing] (gateway_status = "queued")
    │
    ├── Webhook: status = "processed" ──▶ [paid] (auto)
    │       └── Shift reconciliation: approved → paid if all paid
    │
    ├── Webhook: status = "failed" ──▶ [failed] (auto)
    │       └── Shift stays at approved (BR-04)
    │
    ├── Webhook: duplicate delivery ──▶ [processing] (no change, logged as "duplicate")
    │
    ├── Webhook: unknown status ──▶ [processing] (no change, logged as "ignored")
    │
    └── Manual: pix:webhook:verify ──▶ same as webhook (admin-triggered status check)
```

---

## 8. Edge Cases

1. **Duplicate webhook delivery** — Gateway sends the same callback twice (common in banking APIs). The second delivery finds the payment already in `paid`/`failed` status, logs as "duplicate", returns 200. No duplicate audit log. Idempotent.
2. **Webhook for unknown transaction ID** — No payment found with the given `gateway_transaction_id`. Log as "ignored". Return 200 (not 404 — we don't want the gateway to keep retrying for a transaction we don't know about).
3. **Webhook for payment already manually resolved** — Admin manually marked paid while the async webhook was in transit. Payment is already `paid`. Webhook logs as "duplicate". No conflict.
4. **Webhook for payment in `pending` status** — Should not happen (payment must have been released to have a `gateway_transaction_id`). Log as "ignored".
5. **Invalid JSON payload** — `PixWebhookRequest` validation rejects malformed payloads with 422. Gateway may retry. This is acceptable.
6. **Missing `X-Webhook-Signature` header** — Middleware returns 401. Gateway should retry with proper signature.
7. **Signature mismatch** — Middleware returns 401. Could be a spoofing attempt or a config mismatch. Logged as warning.
8. **Gateway sends webhook for a `failed` payment** — Payment is already `failed` (e.g., sync failure in Phase 4B, then gateway sends a delayed webhook). Idempotent — logged as "duplicate", no status change.
9. **Concurrent webhook + manual mark-paid** — Race condition. The first write wins (DB transaction + `payment.refresh()`). The second action sees the payment already in terminal status and is handled as "duplicate" (webhook) or throws RuntimeException (manual mark-paid). Both paths are safe.
10. **Webhook with unknown status value** — E.g., `"status": "cancelled"`. Payment stays in `processing`. Logged as "ignored". Admin can use `pix:webhook:verify` command to manually resolve.
11. **Empty webhook secret in production** — The default `'default-dev-secret-change-in-production'` is intentionally obvious. The middleware logs a warning if the secret hasn't been changed from the default in production (`APP_ENV=production`).
12. **Multiple webhooks in rapid succession** — Each webhook is processed synchronously. No queue. If performance becomes an issue, future phase can dispatch queue jobs. For MVP, synchronous is sufficient.
13. **Webhook log table growth** — Operational data. Consider a scheduled cleanup command in the future (e.g., delete logs older than 90 days). Not in scope for this phase.

---

## 9. Acceptance Criteria

### Migration & Model — PixWebhookLog

- [ ] AC-4C-01: Migration creates `pix_webhook_logs` table with columns: `id`, `gateway_transaction_id`, `payload` (JSON), `status`, `error_message`, `ip_address`, `received_at`, `timestamps`
- [ ] AC-4C-02: Index on `gateway_transaction_id`, `received_at`, and `status`
- [ ] AC-4C-03: `PixWebhookLog` model has fillable attributes matching the schema
- [ ] AC-4C-04: `PixWebhookLog` model casts `payload` as `array` (JSON)
- [ ] AC-4C-05: Migration is reversible (`down()` drops the table)

### Config

- [ ] AC-4C-06: `config/pix.php` has `webhook.secret` from `PIX_WEBHOOK_SECRET` env var with default `'default-dev-secret-change-in-production'`
- [ ] AC-4C-07: `config/pix.php` has `webhook.algorithm` from `PIX_WEBHOOK_ALGORITHM` env var defaulting to `'sha256'`
- [ ] AC-4C-08: `config/pix.php` has `webhook.ip_whitelist` from `PIX_WEBHOOK_IP_WHITELIST` env var (comma-separated, empty = disabled)

### VerifyPixWebhookSignature Middleware

- [ ] AC-4C-09: Middleware returns 401 with JSON `{error: "Missing signature"}` when `X-Webhook-Signature` header is absent
- [ ] AC-4C-10: Middleware returns 401 with JSON `{error: "Invalid signature"}` when HMAC signature does not match
- [ ] AC-4C-11: Middleware passes request through when HMAC signature is valid
- [ ] AC-4C-12: Signature comparison uses `hash_equals()` (timing-safe comparison)
- [ ] AC-4C-13: Middleware computes HMAC over raw request body (`request->getContent()`), not parsed JSON
- [ ] AC-4C-14: When IP whitelist is configured and request IP is not in the list, middleware returns 403 with JSON `{error: "Unauthorized IP"}`
- [ ] AC-4C-15: When IP whitelist is empty (default), IP check is skipped (all IPs allowed)

### PixWebhookRequest — Payload Validation

- [ ] AC-4C-16: Validates `transaction_id` is required string
- [ ] AC-4C-17: Validates `status` is required string. No `in:` rule — unknown status values (e.g. `"cancelled"`) are passed through to `PixWebhookService` and handled as "ignored" per AC-4C-38. This prevents the gateway from receiving 422 errors and retrying indefinitely for status values we don't recognize yet.
- [ ] AC-4C-18: Validates `amount` is nullable string
- [ ] AC-4C-19: Validates `error_code` is nullable string
- [ ] AC-4C-20: Validates `error_message` is nullable string
- [ ] AC-4C-21: Validates `timestamp` is nullable string

### PixWebhookService — Payment Not Found

- [ ] AC-4C-22: When no payment matches `gateway_transaction_id`: creates `PixWebhookLog` with `status = "ignored"` and `error_message` containing "Payment not found"
- [ ] AC-4C-23: Returns HTTP 200 (not 404) so the gateway does not retry

### PixWebhookService — Idempotency (Duplicate)

- [ ] AC-4C-24: When payment is already `paid` and webhook status is `"processed"`: creates `PixWebhookLog` with `status = "duplicate"`, no status change, no new audit log
- [ ] AC-4C-25: When payment is already `failed` and webhook status is `"failed"`: creates `PixWebhookLog` with `status = "duplicate"`, no status change, no new audit log
- [ ] AC-4C-26: Returns HTTP 200 for duplicate webhooks

### PixWebhookService — Payment Not in Processing

- [ ] AC-4C-27: When payment is in `pending` status: creates `PixWebhookLog` with `status = "ignored"` and error message "Payment not in processing status"
- [ ] AC-4C-28: When payment is in a non-processing, non-terminal status: same "ignored" behavior

### PixWebhookService — Webhook Status "processed" (Success)

- [ ] AC-4C-29: Transitions payment to `Paid`, sets `paid_at = now()`, `gateway_status = "processed"`
- [ ] AC-4C-30: Writes `PaymentAuditLog` with `action = Succeed`, `transaction_ref = "webhook-paid-{id}-{uuid}"`, payload with `source = "webhook"`
- [ ] AC-4C-31: Calls `reconcileShiftStatus()` to auto-transition shift `approved → paid` if all payments are now paid
- [ ] AC-4C-32: Creates `PixWebhookLog` with `status = "processed"`

### PixWebhookService — Webhook Status "failed" (Failure)

- [ ] AC-4C-33: Transitions payment to `Failed`, sets `failed_at = now()`, `failure_reason` from gateway `error_message` (or `"Gateway webhook: {error_code}"` if message is null)
- [ ] AC-4C-34: Sets `gateway_status = "failed"` on payment
- [ ] AC-4C-35: Writes `PaymentAuditLog` with `action = Fail`, `error_message` from gateway, payload with `source = "webhook"`
- [ ] AC-4C-36: Does NOT touch shift status (BR-04)
- [ ] AC-4C-37: Creates `PixWebhookLog` with `status = "processed"`

### PixWebhookService — Unknown Status

- [ ] AC-4C-38: When webhook status is not `"processed"` or `"failed"` (e.g., `"queued"`): creates `PixWebhookLog` with `status = "ignored"`, payment unchanged

### PixWebhookService — Audit Trail

- [ ] AC-4C-39: Every `PaymentAuditLog` written by `PixWebhookService` has a unique `transaction_ref` (UUID-based, prefixed with `webhook-*`)
- [ ] AC-4C-40: Audit log payload always includes: `source = "webhook"`, `transaction_id`, `webhook_ip`
- [ ] AC-4C-41: `PaymentAuditLog.payment_id` is always set (not null) for webhook-driven entries

### PixWebhookController

- [ ] AC-4C-42: Returns HTTP 200 with JSON `{status, transaction_id}` on successful processing
- [ ] AC-4C-43: Returns HTTP 200 with JSON `{status: "error"}` on unexpected exceptions (prevents gateway retries on our bugs)
- [ ] AC-4C-44: Logs exceptions at `error` level without exposing internals in the response

### Route

- [ ] AC-4C-45: `POST /webhooks/pix/status` exists and is NOT inside any `auth` middleware group
- [ ] AC-4C-46: Route applies `VerifyPixWebhookSignature` middleware
- [ ] AC-4C-47: Unauthenticated GET to the endpoint returns 405 (Method Not Allowed)

### MockPixGateway::checkPaymentStatus() — Extended

- [ ] AC-4C-48: Returns `PaymentResponse(status="failed")` when `transactionId` contains `"-sync-failed"`
- [ ] AC-4C-49: Returns `PaymentResponse(status="queued")` when `transactionId` contains `"-sync-pending"`
- [ ] AC-4C-50: Returns `PaymentResponse(status="processed")` for all other transaction IDs (default)

### Artisan Command — pix:webhook:verify

- [ ] AC-4C-51: `php artisan pix:webhook:verify {gatewayTransactionId}` finds the payment by `gateway_transaction_id`
- [ ] AC-4C-52: Returns error (exit code 1) if payment not found
- [ ] AC-4C-53: Returns info message (exit code 0) if payment is not in `processing` status ("already resolved")
- [ ] AC-4C-54: Calls `gateway.checkPaymentStatus()` and updates payment status following the same logic as webhook (auto-transition, audit log, shift reconciliation)
- [ ] AC-4C-55: Outputs the resolved status to the console

### No Regressions

- [ ] AC-4C-56: All existing tests (870+ from Phase 1-3C + Phase 4A + Phase 4B tests) continue to pass (0 regressions)
- [ ] AC-4C-57: Existing `PixPaymentService::initiateTransfer()` is unchanged
- [ ] AC-4C-58: Existing `PaymentReleaseService` and `PaymentSettlementService` are unchanged
- [ ] AC-4C-59: The webhook endpoint does not interfere with any existing authenticated routes

---

## 10. Security Considerations

- **Authorization:** The webhook endpoint is **unauthenticated** (no user session). Security is provided entirely by HMAC signature verification (`VerifyPixWebhookSignature` middleware). This is the standard pattern for webhook endpoints.
- **Input Validation:** `PixWebhookRequest` validates payload structure. The raw body (not parsed JSON) is used for signature verification to prevent JSON parsing differences between sender and receiver.
- **Timing-Safe Comparison:** `hash_equals()` is used for HMAC comparison to prevent timing attacks.
- **IP Allowlist:** Optional layer of defense. If configured, only requests from whitelisted IPs are accepted. Empty whitelist (default) disables this check.
- **Container Compliance:** The mock gateway and webhook handler require no external network access. In production, the webhook endpoint receives inbound HTTP from the gateway provider.
- **Financial Safety:**
  - **Idempotency** prevents double-processing. Even if the gateway sends the same callback 10 times, the payment transitions only once.
  - **No funds movement in webhooks** — the webhook only updates status. The actual money movement was initiated in Phase 4B.
  - **Audit trail** — every webhook delivery is logged in `pix_webhook_logs` (operational) and every status change in `payment_audit_logs` (financial).
- **Error Handling:** The controller always returns HTTP 200 to the gateway (except for signature validation failures, which return 401). This prevents infinite retry loops caused by our bugs. Failed processing is logged and can be retried manually via `pix:webhook:verify`.
- **Secret Management:** Webhook secret is stored in `PIX_WEBHOOK_SECRET` env var. Default value is intentionally obvious to catch production misconfiguration. A log warning is emitted if the default secret is used in production.

---

## 11. Notes for Future Phases

- **Phase 5 / US-04:** When a webhook resolves a payment to `failed`, dispatch a notification to the biker. The `PaymentAuditLog` with `source = "webhook"` and `action = Fail` is the trigger event.
- **Future:** Queue-based webhook processing — dispatch a job instead of processing synchronously. Useful if webhook volume is high or processing is slow.
- **Future:** Webhook delivery log admin UI — view all webhook deliveries, filter by status, manually replay failed deliveries.
- **Future:** Scheduled command `pix:webhook:stale` — find payments with `gateway_status = "queued"` older than N hours and automatically check their status via `checkPaymentStatus()`.
- **Future:** Rate limiting on the webhook endpoint to prevent abuse (e.g., 60 requests/minute per IP).
