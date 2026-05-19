# Plan: Phase 4B — PIX Payment Execution (Automated Settlement)

**Task ID:** Phase-4B
**Date:** 2026-05-17
**Planner Version:** 1.0
**Complexity:** Complex

---

## 1. Objective

Bridge the gap between the Admin's release/retry actions (Phase 3B/3C) and the PIX gateway (Phase 4A). When a payment transitions to `processing`, the system now calls the gateway to initiate a real PIX transfer. Synchronous gateway responses (immediate success or failure) are handled automatically — auto-marking the payment as `paid` or `failed` without Admin intervention. Asynchronous responses ("queued") leave the payment in `processing` for Phase 4C webhooks to resolve. The manual mark-paid/mark-failed fallback is preserved for edge cases.

---

## 2. Source References

### User Stories
- (No specific US — core payout engine infrastructure)

### Business Rules
- **BR-02: PIX Verification** — Payments can only be sent to verified PIX keys. The gateway call must target the biker's verified PIX key.
- **BR-03: Manual Release** — The Admin's release/retry action remains the trigger. The gateway call is a consequence of that action, not an automated scheduler.
- **BR-04: Granular Failure** — One gateway failure does not prevent other payments from being sent. Batch release iterates independently.
- **BR-06: Payment Retries** — Every gateway attempt (including auto-retries from Phase 3C) must write a unique audit log entry with a unique `transaction_ref`.

### PRD Sections
- Section 1 (Executive Summary): "integrates with a banking API for bulk PIX payments"
- Section 2C (The Admin/Controller): "Reviews 'Closed' shifts, verifies margins, and clicks 'Release Payment.'"
- Section 4 (BR-03): "No automated payments occur without explicit Admin 'Approval' per shift."

### Tech Doc Sections
- Section 3 (Business Logic & Formulas): Payout formula
- Section 5 (Security & Guardrails): BR-02, BR-03, BR-06

### ADR References
- ADR-001: Core Payout Schema — Payment state machine (`pending → processing → paid/failed`)
- ADR-006 D2: Two-tier eligibility — release requires verified PIX (hard block)
- ADR-006 D3: Batch release skips ineligible — one failure doesn't abort others
- ADR-006 D4: Shift auto-transition gates — `approved → paid` when all payments paid
- ADR-006 D5: Hard retry cap at 3 — gateway calls during retry must respect this

### Phase 4A Dependency
- `PixGatewayInterface::initiatePayment()` — defined in Phase 4A, used here
- `MockPixGateway::initiatePayment()` — extended in Phase 4B with response scenarios

---

## 3. Scope

### In Scope
1. `PixPaymentService` — wraps gateway `initiatePayment()` calls with audit logging, payment status management, and shift reconciliation
2. Extend `MockPixGateway::initiatePayment()` to support three response scenarios: `processed` (sync success), `failed` (sync failure), `queued` (async pending)
3. New migration: `gateway_transaction_id` and `gateway_status` columns on `payments` table
4. Modify `PaymentReleaseService::releasePayment()` — after transitioning to `processing`, call `PixPaymentService` to initiate the gateway transfer
5. Modify `PaymentSettlementService::retry()` — after transitioning to `processing`, call `PixPaymentService` to initiate the gateway transfer
6. Auto-transition logic: sync gateway success → `paid`, sync gateway failure → `failed`
7. Update `PaymentSettlementService::getSettlementData()` — display gateway transaction ID and gateway status in settlement dashboard
8. Update existing Blade views to show gateway status and transaction ID
9. Unit tests for `PixPaymentService`
10. Feature tests for updated release and retry flows with gateway integration

### Out of Scope
1. PIX key verification — Phase 4A
2. Webhook handling for async gateway responses — Phase 4C
3. Real PIX gateway implementation (FitBank, Stark Bank) — mock only
4. Scheduled/automated payment release — future enhancement (PRD Section 6: "Timer-Based Auto-Pay")
5. Changes to the mark-paid/mark-failed manual fallback — preserved as-is
6. Changes to the close flow — payment creation stays unchanged
7. Admin dashboard / margin reporting — Phase 5 / US-03

### Open Questions
None. All architectural decisions are covered by existing ADRs and Phase 4A.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Not relevant to payment execution |
| BR-02 PIX Verification | **Yes** | Gateway call targets the biker's verified PIX key. The verified key must be resolved at call time. |
| BR-03 Manual Release | **Yes** | Gateway call is triggered by Admin's explicit release/retry action, not automated. Admin remains in control. |
| BR-04 Granular Failure | **Yes** | Batch release calls gateway per-payment. One gateway failure auto-fails only that payment; others proceed independently. |
| BR-05 Last Minute Biker | No | Not relevant to payment execution |
| BR-06 Payment Retries | **Yes** | Every gateway attempt writes a `PaymentAuditLog` with unique `transaction_ref`. Retry cap (3) is enforced before the gateway call. |

---

## 5. Schema Changes

### New Tables

No new tables.

### Modified Tables

```
payments
├── + gateway_transaction_id    STRING(255) NULLABLE    — External gateway transaction identifier (returned by bank API)
├── + gateway_status            STRING(50) NULLABLE     — Last known gateway status: "queued", "processed", "failed", null
└── timestamps
```

### Indexes

- `idx_payments_gateway_transaction_id` on `payments(gateway_transaction_id)` — lookup by gateway transaction ID for webhook reconciliation (Phase 4C)

### Financial Column Checklist

No new financial columns. Existing `amount DECIMAL(12,2)` is passed to the gateway as a BCMath string.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Migration | `database/migrations/2026_05_17_000002_add_gateway_columns_to_payments_table.php` | Add `gateway_transaction_id`, `gateway_status` to payments |
| Service | `app/Services/PixPaymentService.php` | Gateway call orchestrator — initiate transfer, handle response, auto-transition status |
| Test | `tests/Unit/Services/PixPaymentServiceTest.php` | Unit tests for PixPaymentService |
| Test | `tests/Feature/Controllers/PaymentReleaseWithGatewayTest.php` | Feature tests for release flow with gateway integration |
| Test | `tests/Feature/Controllers/PaymentRetryWithGatewayTest.php` | Feature tests for retry flow with gateway integration |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Model | `app/Models/Payment.php` | Add `gateway_transaction_id`, `gateway_status` to `$fillable` and `$casts` |
| Service | `app/Services/Gateway/MockPixGateway.php` | Extend `initiatePayment()` to support three response scenarios based on amount pattern or config flag |
| Service | `app/Services/PaymentReleaseService.php` | After status→processing + audit log, call `PixPaymentService::initiateTransfer()` |
| Service | `app/Services/PaymentSettlementService.php` | In `retry()`, after status→processing + audit log (before retry cap auto-fail), call `PixPaymentService::initiateTransfer()` |
| Service | `app/Services/PaymentSettlementService.php` | In `getSettlementData()`, include `gateway_transaction_id` and `gateway_status` in payment item data |
| Enum | `app/Enums/PaymentAuditAction.php` | Add `GatewayAttempt = 'gateway_attempt'` case for gateway call audit entries |
| View | `resources/views/shifts/payment-status.blade.php` | Show gateway transaction ID and gateway status per payment |
| View | `resources/views/shifts/payment-review.blade.php` | Show gateway status for payments already in processing (if any) |

---

## 7. Pseudocode

### PixPaymentService — Core Logic

```
CLASS PixPaymentService:

    CONSTRUCTOR(gateway: PixGatewayInterface)

    FUNCTION initiateTransfer(Payment payment, User admin) -> Payment:
        // Guard: payment must be in processing status
        IF payment.status != Processing:
            THROW RuntimeException("Payment must be in processing status to initiate transfer")

        // Resolve the biker's verified PIX key
        biker = payment.shiftBiker.biker
        verifiedPixKey = biker.pixKeys().where('is_verified', true).first()

        // Guard: must have a verified PIX key (should never happen — release already checks this)
        IF verifiedPixKey IS null:
            THROW RuntimeException("No verified PIX key found for biker")

        // Call gateway
        TRY:
            response = gateway.initiatePayment(
                paymentId = payment.id,
                pixKey = verifiedPixKey.key_value,
                amount = payment.amount    // BCMath string, already DECIMAL(12,2)
            )
        CATCH RuntimeException e:
            // Gateway unreachable — log and leave payment in processing
            // Admin can manually mark paid/failed later
            PaymentAuditLog.create(
                payment_id = payment.id,
                action = GatewayAttempt,
                transaction_ref = "gateway-error-{payment.id}-{uuid}",
                error_message = "Gateway unreachable: {e.message}",
                payload = { amount, pix_key_id, error_type: "gateway_exception" }
            )
            payment.gateway_status = "error"
            payment.save()
            RETURN payment

        // Store gateway transaction ID and status
        payment.gateway_transaction_id = response.transaction_id
        payment.gateway_status = response.status
        payment.save()

        // Write audit log for gateway attempt
        PaymentAuditLog.create(
            payment_id = payment.id,
            action = GatewayAttempt,
            transaction_ref = "gateway-attempt-{payment.id}-{uuid()}",
            payload = {
                transaction_id = response.transaction_id,
                gateway_status = response.status,
                amount = payment.amount,
                pix_key_id = verifiedPixKey.id,
                pix_key_value = verifiedPixKey.key_value,
                initiated_by = admin.id
            }
        )

        // Handle synchronous gateway response
        IF response.status == "processed":
            // Sync success — auto-transition to paid
            payment.status = Paid
            payment.paid_at = now()
            payment.gateway_status = "processed"
            payment.save()

            PaymentAuditLog.create(
                payment_id = payment.id,
                action = Succeed,
                transaction_ref = "gateway-paid-{payment.id}-{uuid()}",
                payload = {
                    source = "gateway_auto",
                    transaction_id = response.transaction_id,
                    paid_at = payment.paid_at,
                    amount = payment.amount
                }
            )

            // Reconcile shift status (approved → paid if all paid)
            reconcileShiftStatus(payment.shiftBiker.shift)

        ELSE IF response.status == "failed":
            // Sync failure — auto-transition to failed
            payment.status = Failed
            payment.failed_at = now()
            payment.failure_reason = response.error_message ?? "Gateway returned failure"
            payment.gateway_status = "failed"
            payment.save()

            PaymentAuditLog.create(
                payment_id = payment.id,
                action = Fail,
                transaction_ref = "gateway-failed-{payment.id}-{uuid()}",
                error_message = response.error_message,
                payload = {
                    source = "gateway_auto",
                    transaction_id = response.transaction_id,
                    error_code = response.error_code,
                    failed_at = payment.failed_at,
                    amount = payment.amount
                }
            )

            // BR-04: DO NOT touch shift.status — failed payment leaves shift at approved

        ELSE IF response.status == "queued":
            // Async — payment stays in processing, Phase 4C webhook will resolve
            // gateway_status already set to "queued"
            // No auto-transition

        RETURN payment
```

### PaymentReleaseService::releasePayment() — Modified Flow

```
FUNCTION releasePayment(Payment payment, User admin) -> Payment:
    // ... existing eligibility checks (BR-02, ADR-005 D4) ...
    // ... existing status transition to processing ...
    // ... existing audit log (action = Release) ...
    // ... existing shift auto-transition check ...

    // NEW: Initiate gateway transfer
    app(PixPaymentService::class).initiateTransfer(payment, admin)

    RETURN payment->refresh()   // Refresh to pick up any auto-transitions from gateway
```

### PaymentSettlementService::retry() — Modified Flow

```
FUNCTION retry(Payment payment, User admin) -> Payment:
    // ... existing failed status check ...
    // ... existing retry cap check (>= 3) ...
    // ... existing eligibility re-check ...
    // ... existing status transition to processing ...
    // ... existing retry_count increment ...
    // ... existing audit log (action = Retry) ...

    // NEW: Initiate gateway transfer (only if NOT auto-failed by retry cap)
    IF NOT retryCapReached:
        app(PixPaymentService::class).initiateTransfer(payment, admin)

    // ... existing retry cap auto-fail logic (unchanged) ...

    RETURN payment->refresh()
```

### MockPixGateway::initiatePayment() — Extended

```
FUNCTION initiatePayment(paymentId, pixKey, amount):
    // Deterministic mock based on amount patterns for testing:
    // - Amount ends with ".01" → sync success (processed)
    // - Amount ends with ".02" → sync failure (failed)
    // - All other amounts → async queued (default)
    //
    // Special override: if pixKey starts with "FAIL-" → force failure
    // Special override: if pixKey starts with "ERROR" → throw RuntimeException (gateway down)

    IF pixKey STARTS WITH "ERROR":
        THROW RuntimeException("Gateway connection timeout")

    IF pixKey STARTS WITH "FAIL-":
        RETURN PaymentResponse(
            success = false,
            transaction_id = null,
            status = "failed",
            error_code = "REJECTED_BY_RECEIVER",
            error_message = "Destinatário rejeitou o pagamento"
        )

    IF amount ENDS WITH ".01":
        txnId = "mock-txn-{paymentId}-{timestamp}"
        RETURN PaymentResponse(
            success = true,
            transaction_id = txnId,
            status = "processed"
        )

    IF amount ENDS WITH ".02":
        txnId = "mock-txn-{paymentId}-{timestamp}"
        RETURN PaymentResponse(
            success = false,
            transaction_id = txnId,
            status = "failed",
            error_code = "INSUFFICIENT_FUNDS",
            error_message = "Saldo insuficiente para PIX"
        )

    // Default: async queued
    txnId = "mock-txn-{paymentId}-{timestamp}"
    RETURN PaymentResponse(
        success = true,
        transaction_id = txnId,
        status = "queued"
    )
```

### State Transitions — Gateway-Enhanced

```
[pending]
    │
    ├──(Admin: Release)──▶ [processing]
    │       │                    │
    │       │                    ├── Gateway: processed ──▶ [paid] (auto)
    │       │                    ├── Gateway: failed ──▶ [failed] (auto)
    │       │                    ├── Gateway: queued ──▶ [processing] (stays)
    │       │                    └── Gateway: error ──▶ [processing] (stays, gateway_status = "error")
    │       │
    [failed]
    │       │
    ├──(Admin: Retry, retry_count < 3)──▶ [processing]
    │       │                    │
    │       │                    ├── Gateway: processed ──▶ [paid] (auto)
    │       │                    ├── Gateway: failed ──▶ [failed] (auto, retry_count unchanged — gateway failed, not a new retry attempt)
    │       │                    ├── Gateway: queued ──▶ [processing] (stays)
    │       │                    └── Gateway: error ──▶ [processing] (stays)
    │       │
    ├──(Admin: Retry, retry_count = 2 → becomes 3)──▶ [failed] (auto-fail by cap, NO gateway call)
```

### Route Design

No new routes. Existing release and retry routes now trigger gateway calls internally.

---

## 8. Edge Cases

1. **Gateway unreachable during release** — Gateway throws RuntimeException. Payment stays in `processing` with `gateway_status = "error"`. Audit log records the exception. Admin can manually mark paid (if they verify externally) or mark failed. No auto-fail on gateway exception — the money has been conceptually released.
2. **Gateway unreachable during retry** — Same behavior as release. Payment stays in `processing` with `gateway_status = "error"`. The retry_count has already been incremented — this is intentional, the Admin chose to retry.
3. **Sync gateway success on release** — Payment auto-transitions `processing → paid`. Shift reconciliation runs. Admin sees the payment as `paid` immediately after clicking release.
4. **Sync gateway success on retry** — Payment auto-transitions `processing → paid`. Shift reconciliation runs. The retry_count reflects the number of attempts.
5. **Sync gateway failure on release** — Payment auto-transitions `processing → failed` with gateway error message. Admin can retry (retry_count is 0 — this is the first attempt, and it was the gateway that failed, not a retry).
6. **Sync gateway failure on retry** — Payment auto-transitions `processing → failed` with gateway error message. Retry_count has already been incremented by the retry logic. If this was the 3rd retry, the auto-fail cap logic handles it before the gateway call.
7. **Retry cap (3) reached — no gateway call** — When `retry_count >= 3`, the `PaymentSettlementService::retry()` throws RuntimeException BEFORE any gateway call. No money moves. When `retry_count` becomes 3 (3rd retry), the service auto-fails the payment and does NOT call the gateway.
8. **Biker has multiple verified PIX keys** — `PixPaymentService` picks the first verified key (`pixKeys().where('is_verified', true).first()`). If the Admin wants to target a specific key, they should unverify the others first (Phase 4A).
9. **Gateway returns transaction_id = null** — `gateway_transaction_id` is set to null on the payment. This can happen for some error responses. The audit log captures the null value for traceability.
10. **Concurrent release + gateway response** — The release and gateway call happen within the same request context. If the gateway returns synchronously, the entire state transition happens in sequence. No race condition.
11. **Gateway returns unknown status** — Any status not in `["processed", "failed", "queued"]` is treated as "queued" (payment stays in processing). `gateway_status` stores whatever the gateway returned for debugging.
12. **Manual mark-paid after gateway queued** — If the gateway returned "queued" (async) but the Admin manually marks as paid (e.g., they confirmed externally), this is allowed. The manual `markPaid` overwrites the status. The audit trail shows both the gateway attempt and the manual confirmation.
13. **Batch release with mixed gateway responses** — Some payments get sync success (auto-paid), some get sync failure (auto-failed), some get queued. Each is independent (BR-04). The batch result counts must account for gateway-driven state changes.

---

## 9. Acceptance Criteria

### Migration & Model

- [ ] AC-4B-01: Migration adds `gateway_transaction_id STRING(255) NULLABLE` to `payments` table after `retry_count`
- [ ] AC-4B-02: Migration adds `gateway_status STRING(50) NULLABLE` to `payments` table after `gateway_transaction_id`
- [ ] AC-4B-03: Index `idx_payments_gateway_transaction_id` exists on `payments(gateway_transaction_id)`
- [ ] AC-4B-04: `Payment` model has `gateway_transaction_id` and `gateway_status` in `$fillable`
- [ ] AC-4B-05: `Payment` model casts `gateway_transaction_id` as `string` and `gateway_status` as `string`
- [ ] AC-4B-06: Migration is reversible (`down()` drops the two columns and index)

### PaymentAuditAction Enum

- [ ] AC-4B-07: `PaymentAuditAction` enum has a new `GatewayAttempt = 'gateway_attempt'` case

### MockPixGateway — Extended

- [ ] AC-4B-08: `MockPixGateway::initiatePayment()` returns `PaymentResponse(status="processed", transaction_id="mock-txn-{id}-{ts}")` when amount ends with `.01`
- [ ] AC-4B-09: `MockPixGateway::initiatePayment()` returns `PaymentResponse(status="failed", error_code="INSUFFICIENT_FUNDS")` when amount ends with `.02`
- [ ] AC-4B-10: `MockPixGateway::initiatePayment()` returns `PaymentResponse(status="queued", transaction_id="mock-txn-{id}-{ts}")` for all other amounts (default)
- [ ] AC-4B-11: `MockPixGateway::initiatePayment()` returns failure response when `pixKey` starts with `"FAIL-"`
- [ ] AC-4B-12: `MockPixGateway::initiatePayment()` throws `RuntimeException` when `pixKey` starts with `"ERROR"`

### PixPaymentService — Gateway Call

- [ ] AC-4B-13: `PixPaymentService::initiateTransfer()` throws `RuntimeException` if payment is not in `processing` status
- [ ] AC-4B-14: Resolves the biker's verified PIX key via `pixKeys().where('is_verified', true).first()`
- [ ] AC-4B-15: Throws `RuntimeException` if no verified PIX key is found
- [ ] AC-4B-16: Calls `gateway.initiatePayment(paymentId, pixKeyValue, amount)` with the payment amount as BCMath string

### PixPaymentService — Gateway Success (Exception)

- [ ] AC-4B-17: On gateway RuntimeException (unreachable): does NOT change payment status (stays `processing`)
- [ ] AC-4B-18: On gateway RuntimeException: sets `gateway_status = "error"` on payment and saves
- [ ] AC-4B-19: On gateway RuntimeException: writes `PaymentAuditLog` with `action = GatewayAttempt`, `error_message` containing the exception message, and payload with `error_type = "gateway_exception"`
- [ ] AC-4B-20: On gateway RuntimeException: returns the payment (no crash propagation)

### PixPaymentService — Gateway Response Processed (Sync Success)

- [ ] AC-4B-21: When gateway returns `status = "processed"`: auto-transitions payment to `Paid`, sets `paid_at = now()`
- [ ] AC-4B-22: Stores `gateway_transaction_id` and `gateway_status = "processed"` on the payment
- [ ] AC-4B-23: Writes `PaymentAuditLog` with `action = Succeed`, `transaction_ref = "gateway-paid-{id}-{uuid}"`, payload with `source = "gateway_auto"`
- [ ] AC-4B-24: Calls `reconcileShiftStatus()` to auto-transition shift `approved → paid` if all payments are now paid

### PixPaymentService — Gateway Response Failed (Sync Failure)

- [ ] AC-4B-25: When gateway returns `status = "failed"`: auto-transitions payment to `Failed`, sets `failed_at = now()`, `failure_reason = gateway error_message`
- [ ] AC-4B-26: Stores `gateway_transaction_id` and `gateway_status = "failed"` on the payment
- [ ] AC-4B-27: Writes `PaymentAuditLog` with `action = Fail`, `error_message` from gateway, payload with `source = "gateway_auto"`
- [ ] AC-4B-28: Does NOT touch shift status (BR-04 — failed payment does not regress shift)

### PixPaymentService — Gateway Response Queued (Async)

- [ ] AC-4B-29: When gateway returns `status = "queued"`: payment stays in `Processing`, no status change
- [ ] AC-4B-30: Stores `gateway_transaction_id` and `gateway_status = "queued"` on the payment
- [ ] AC-4B-31: Writes `PaymentAuditLog` with `action = GatewayAttempt`, payload containing `gateway_status = "queued"`

### PixPaymentService — Gateway Response Unknown

- [ ] AC-4B-32: When gateway returns an unrecognized status: treats as "queued" — payment stays in `Processing`, stores the raw `gateway_status` value

### PixPaymentService — Audit Trail

- [ ] AC-4B-33: Every `PaymentAuditLog` written by `PixPaymentService` has a unique `transaction_ref` (UUID-based, prefixed with `gateway-*`)
- [ ] AC-4B-34: Audit log payload always includes: `transaction_id`, `gateway_status`, `amount`, `pix_key_id`, `initiated_by`

### Integration — Release Flow

- [ ] AC-4B-35: `PaymentReleaseService::releasePayment()` calls `PixPaymentService::initiateTransfer()` after the payment transitions to `processing` and the release audit log is written
- [ ] AC-4B-36: Release returns `$payment->refresh()` so the caller sees any gateway-driven state changes
- [ ] AC-4B-37: If gateway auto-transitions payment to `Paid`, the controller response reflects the `paid` status (not `processing`)
- [ ] AC-4B-38: Batch release (`releaseAllEligiblePayments`) works correctly with gateway integration — each payment's gateway response is handled independently (BR-04)

### Integration — Retry Flow

- [ ] AC-4B-39: `PaymentSettlementService::retry()` calls `PixPaymentService::initiateTransfer()` after the payment transitions to `processing` and the retry audit log is written, but ONLY if the retry cap has NOT been reached
- [ ] AC-4B-40: When retry cap is reached (retry_count becomes 3): NO gateway call is made — the auto-fail logic handles the terminal failure
- [ ] AC-4B-41: Retry returns `$payment->refresh()` so the caller sees any gateway-driven state changes

### Settlement Dashboard — Display Updates

- [ ] AC-4B-42: `PaymentSettlementService::getSettlementData()` includes `gateway_transaction_id` and `gateway_status` in each payment item
- [ ] AC-4B-43: `payment-status.blade.php` shows the gateway transaction ID for payments that have one (labeled "ID Transação")
- [ ] AC-4B-44: `payment-status.blade.php` shows the gateway status as a badge: "Processado" (processed), "Na fila" (queued), "Erro" (error), "Falhou" (failed)
- [ ] AC-4B-45: `payment-review.blade.php` shows gateway status for payments already in `processing` state (if Admin navigates back)

### No Regressions

- [ ] AC-4B-46: All existing 870+ tests continue to pass (0 regressions)
- [ ] AC-4B-47: Manual mark-paid (`PaymentSettlementService::markPaid`) still works as fallback when payment is in `processing` status (e.g., gateway returned "queued" or "error")
- [ ] AC-4B-48: Manual mark-failed (`PaymentSettlementService::markFailed`) still works as fallback
- [ ] AC-4B-49: Existing `PaymentReleaseService` eligibility checks (BR-02, ADR-005 D4) are unchanged
- [ ] AC-4B-50: Existing `PaymentSettlementService::retry()` retry cap logic (ADR-006 D5) is unchanged

---

## 10. Security Considerations

- **Authorization:** No new endpoints. Gateway calls are triggered by existing admin-only release/retry actions. Authorization is enforced at the controller/policy layer (unchanged).
- **Input Validation:** No new user input. Gateway parameters (payment ID, PIX key, amount) are derived from existing validated data. The payment amount is a BCMath string from `DECIMAL(12,2)` — no injection risk.
- **Container Compliance:** `MockPixGateway` requires no external network access. The gateway interface is designed for future HTTP client injection.
- **Financial Safety:**
  - **No double-pay prevention needed here** — the `processing` status guard in `releasePayment()` and `retry()` already prevents double-release. The gateway is called exactly once per release/retry action.
  - **Gateway transaction ID** provides an external reconciliation key. If the same payment is somehow sent twice (race condition), the gateway transaction ID will differ, making the duplicate detectable.
  - **Auto-fail on sync failure** prevents orphaned `processing` payments — the Admin doesn't need to manually clean up gateway failures.
  - **No auto-fail on gateway exception** — if the gateway is down, the payment stays in `processing` for the Admin to resolve. This is safer than auto-failing, which could cause false negatives if the payment actually succeeded but the response was lost.
- **Audit Trail:** Every gateway call (success, failure, exception) writes a `PaymentAuditLog`. The `GatewayAttempt` action records the request; the `Succeed`/`Fail` action records the outcome. Together they form a complete request-response audit pair.

---

## 11. Notes for Future Phases

- **Phase 4C** will use `gateway_transaction_id` to match incoming webhook payloads to payments. The index on this column is critical for webhook performance.
- **Phase 4C** will also use `gateway_status` to determine which payments need status updates (those with `gateway_status = "queued"`).
- **Future:** When a real gateway is integrated, `MockPixGateway` is replaced by e.g. `StarkBankPixGateway`. The `PixPaymentService` and all calling code remain unchanged — only the container binding changes.
- **Future:** Add a config flag `pix.gateway.auto_settle` to disable auto-transition on sync success, forcing manual mark-paid even for gateway-confirmed payments. Useful during rollout when trust in the gateway is being established.
