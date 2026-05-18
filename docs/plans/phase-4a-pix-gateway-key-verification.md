# Plan: Phase 4A — PIX Gateway Interface & Key Verification

**Task ID:** Phase-4A
**Date:** 2026-05-17
**Planner Version:** 1.0
**Complexity:** Medium

---

## 1. Objective

Establish the PIX gateway abstraction layer and implement BR-02 (PIX Verification) by connecting the existing `PixKey.verified_at` / `PixKey.is_verified` columns to an actual verification flow. This phase creates the `PixGatewayInterface` contract, a `MockPixGateway` implementation for development/testing, and a `PixVerificationService` that the Admin triggers to verify a biker's PIX key against the bank API. It does **not** execute payments — that is Phase 4B.

---

## 2. Source References

### User Stories
- (No specific US — foundational infrastructure for BR-02 enforcement already in place since Phase 1)

### Business Rules
- **BR-02: PIX Verification** — "PIX keys must be validated against the Bank API (Account Holder Name) before payment is enabled." This phase implements the **verification action** that transitions `is_verified = false` → `true`.

### PRD Sections
- Section 2C (The Admin/Controller): "Manages Restaurant contracts (Rates) and Biker data (PIX)."
- Section 4 (BR-02): "PIX keys must be validated against the Bank API (Account Holder Name) before payment is enabled."

### Tech Doc Sections
- Section 5 (Security & Guardrails): "PIX Verification (BR-02): Admin must manually verify a Biker's PIX key identity before the first payout is enabled."

### ADR References
- ADR-001: Core Payout Schema — `pix_keys` table definition
- ADR-006 D2: Two-tier eligibility policy (warning at close, hard block at release/retry) — both tiers depend on `is_verified = true`
- ADR-006 D7: Re-eligibility check on retry — calls `hasVerifiedPixKey()` which depends on this verification

---

## 3. Scope

### In Scope
1. `PixGatewayInterface` contract with three methods: `verifyKey()`, `initiatePayment()`, `checkPaymentStatus()`
2. `MockPixGateway` implementation (deterministic, test-friendly)
3. `PixVerificationService` — orchestrates BR-02 verification: calls gateway, updates `pix_keys` row, writes audit log
4. Admin endpoint: `POST /admin/pix-keys/{pixKey}/verify` — triggers verification
5. Admin endpoint: `GET /admin/bikers/{biker}/pix-keys` — list/manage PIX keys for a biker
6. `PixKeyController` (Admin) — controller for verification + listing
7. Blade view for PIX key management (list + verify button)
8. `config/pix.php` — gateway driver configuration
9. `PixGatewayServiceProvider` — binds interface to implementation via container
10. `PaymentAuditLog` entries for verification events (new action: `PaymentAuditAction::VerifyPix`)
11. Unit tests for `MockPixGateway`, `PixVerificationService`
12. Feature tests for `PixKeyController` endpoints

### Out of Scope
1. Real PIX gateway integration (FitBank, Stark Bank, etc.) — mock only
2. PIX payment execution — Phase 4B
3. Webhook handling for async gateway responses — Phase 4C
4. Biker self-service PIX key management (PRD Section 2B mentions self-registration, but that's a future enhancement)
5. Changes to existing payment release/settlement flows — they already check `is_verified`; this phase only provides the means to set it
6. Changes to shift close/review flows — they already show warnings for unverified keys

### Resolved Questions
1. **OQ-4A-01:** ✅ Admin **can unverify** a previously verified PIX key. The "Desverificar" action resets `is_verified = false`, clears `verified_at` and `account_holder_name`. This allows the Admin to handle biker PIX key changes without deleting/recreating the record. Audit trail captures unverifcation events. — *Resolved by Product Owner: 2026-05-17*
2. **OQ-4A-02:** ✅ Multiple PIX keys per biker **coexist**. The existing schema (`hasMany`) is preserved. `hasVerifiedPixKey()` requires at least one verified key. Verifying/unverifying one key does not affect others. — *Resolved by Product Owner: 2026-05-17*

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Not relevant to PIX key management |
| BR-02 PIX Verification | **Yes** | **Primary rule.** Verification must call gateway, retrieve `account_holder_name`, and set `is_verified = true` + `verified_at = now()`. |
| BR-03 Manual Release | No | Not relevant — payment release stays unchanged |
| BR-04 Granular Failure | No | Single-key operations, no batch implications |
| BR-05 Last Minute Biker | No | Not relevant to PIX key management |
| BR-06 Payment Retries | No | Not relevant — retry flow unchanged |

---

## 5. Schema Changes

### New Tables

No new tables.

### Modified Tables

```
pix_keys
├── (no schema changes — columns already exist: is_verified, verified_at, account_holder_name)
└── timestamps
```

> The existing schema already has all necessary columns. This phase only **populates** them via the gateway verification flow.

### Indexes

No new indexes — existing unique constraint on `(biker_id, key_type, key_value)` is sufficient.

### Financial Column Checklist

No financial columns in this phase.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Contract | `app/Contracts/PixGatewayInterface.php` | Gateway abstraction — verifyKey, initiatePayment, checkPaymentStatus |
| Service | `app/Services/Gateway/MockPixGateway.php` | Mock implementation for dev/test — deterministic responses |
| Service | `app/Services/PixVerificationService.php` | BR-02 verification orchestrator — calls gateway, updates pix_keys, writes audit |
| Controller | `app/Http/Controllers/Admin/PixKeyController.php` | Admin endpoints: list biker's keys, verify, unverify |
| Request | `app/Http/Requests/VerifyPixKeyRequest.php` | Empty form request for future validation extensibility |
| View | `resources/views/admin/bikers/pix-keys.blade.php` | PIX key management view with verify/unverify buttons |
| Config | `config/pix.php` | Gateway driver, mock settings, timeout |
| Provider | `app/Providers/PixGatewayServiceProvider.php` | Binds PixGatewayInterface → implementation via container |
| Test | `tests/Unit/Services/Gateway/MockPixGatewayTest.php` | Unit tests for mock gateway responses |
| Test | `tests/Unit/Services/PixVerificationServiceTest.php` | Unit tests for verification service logic |
| Test | `tests/Feature/Controllers/Admin/PixKeyControllerTest.php` | Feature tests for all controller endpoints |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Enum | `app/Enums/PaymentAuditAction.php` | Add `VerifyPix` case for audit trail |
| Config | `config/services.php` | Add pix gateway base config (or delegate to config/pix.php) |
| Provider | `app/Providers/AppServiceProvider.php` | Register `PixGatewayServiceProvider` (or register binding here) |
| Routes | `routes/web.php` | Add 3 admin routes for PIX key management |
| View | `resources/views/shifts/close-review.blade.php` | Add "Verificar PIX" link next to unverified key warnings |
| View | `resources/views/shifts/payment-review.blade.php` | Add "Verificar PIX" link next to blocked ineligible bikers |
| View | `resources/views/shifts/payment-status.blade.php` | Add "Verificar PIX" link next to failed/retry-capped payments |
| View | `resources/views/shifts/show.blade.php` | (Optional) Link to biker PIX key management |

---

## 7. Pseudocode

### PixGatewayInterface Contract

```
INTERFACE PixGatewayInterface:

    STRUCT VerifyKeyResponse:
        success: bool
        account_holder_name: string|null
        error_code: string|null
        error_message: string|null

    STRUCT PaymentResponse:
        success: bool
        transaction_id: string|null
        status: string  // "queued", "processed", "failed"
        error_code: string|null
        error_message: string|null

    FUNCTION verifyKey(keyType: PixKeyType, keyValue: string) -> VerifyKeyResponse
    FUNCTION initiatePayment(paymentId: int, pixKey: string, amount: string) -> PaymentResponse
    FUNCTION checkPaymentStatus(transactionId: string) -> PaymentResponse
```

### MockPixGateway Implementation

```
CLASS MockPixGateway IMPLEMENTS PixGatewayInterface:

    FUNCTION verifyKey(keyType, keyValue):
        // Deterministic mock: always succeeds with a fake holder name
        // Exception: if keyValue starts with "FAIL" → return failure response
        // Exception: if keyValue starts with "ERROR" → throw RuntimeException (simulates gateway down)
        IF keyValue STARTS WITH "FAIL":
            RETURN VerifyKeyResponse(success=false, error_code="KEY_NOT_FOUND", error_message="Chave PIX não encontrada")
        IF keyValue STARTS WITH "ERROR":
            THROW RuntimeException("Gateway connection timeout")
        RETURN VerifyKeyResponse(success=true, account_holder_name="MOCK HOLDER for {keyValue}")

    FUNCTION initiatePayment(paymentId, pixKey, amount):
        // Stub — returns "queued" for Phase 4B implementation
        RETURN PaymentResponse(success=true, transaction_id="mock-txn-{paymentId}", status="queued")

    FUNCTION checkPaymentStatus(transactionId):
        // Stub — returns "processed" for Phase 4C implementation
        RETURN PaymentResponse(success=true, transaction_id=transactionId, status="processed")
```

### PixVerificationService — Core Logic

```
CLASS PixVerificationService:

    CONSTRUCTOR(gateway: PixGatewayInterface)

    FUNCTION verify(PixKey pixKey, User admin) -> PixKey:
        // Guard: key must not already be verified
        IF pixKey.is_verified:
            THROW RuntimeException("PIX key is already verified")

        // Guard: biker must exist
        IF pixKey.biker DOES NOT EXIST:
            THROW RuntimeException("Biker not found")

        // Call gateway
        response = gateway.verifyKey(pixKey.key_type, pixKey.key_value)

        IF NOT response.success:
            // Write audit log — verification failed
            PaymentAuditLog.create(
                payment_id = null,
                action = VerifyPix,
                transaction_ref = "pix-verify-fail-{pixKey.id}-{uuid}",
                error_message = response.error_message,
                payload = { pix_key_id, biker_id, key_type, key_value, error_code }
            )
            THROW RuntimeException("PIX verification failed: {response.error_message}")

        // Success — update pix_key
        pixKey.is_verified = true
        pixKey.verified_at = now()
        pixKey.account_holder_name = response.account_holder_name
        pixKey.save()

        // Write audit log — verification succeeded
        PaymentAuditLog.create(
            payment_id = null,
            action = VerifyPix,
            transaction_ref = "pix-verify-ok-{pixKey.id}-{uuid}",
            payload = { pix_key_id, biker_id, key_type, key_value, account_holder_name, verified_by: admin.id }
        )

        RETURN pixKey

    FUNCTION unverify(PixKey pixKey, User admin) -> PixKey:
        // Guard: key must be verified
        IF NOT pixKey.is_verified:
            THROW RuntimeException("PIX key is not verified")

        pixKey.is_verified = false
        pixKey.verified_at = null
        pixKey.account_holder_name = null
        pixKey.save()

        // Write audit log
        PaymentAuditLog.create(
            payment_id = null,
            action = VerifyPix,  // or a new UnverifyPix action
            transaction_ref = "pix-unverify-{pixKey.id}-{uuid}",
            payload = { pix_key_id, biker_id, key_type, key_value, unverifed_by: admin.id }
        )

        RETURN pixKey
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware |
|--------|-----|-------------------|------|------------|
| GET | `/admin/bikers/{biker}/pix-keys` | `PixKeyController@index` | Admin | `auth`, `role:admin` |
| POST | `/admin/pix-keys/{pixKey}/verify` | `PixKeyController@verify` | Admin | `auth`, `role:admin` |
| POST | `/admin/pix-keys/{pixKey}/unverify` | `PixKeyController@unverify` | Admin | `auth`, `role:admin` |

### State Transitions — PIX Key Verification

```
[is_verified = false]
        │
        ├──(Admin clicks "Verificar")──▶ Gateway verifyKey()
        │       │
        │       ├──(success)──▶ [is_verified = true, verified_at = now(), account_holder_name set]
        │       │
        │       └──(failure)──▶ [is_verified = false (unchanged)] + RuntimeException
        │
[is_verified = true]
        │
        └──(Admin clicks "Desverificar")──▶ [is_verified = false, verified_at = null, account_holder_name = null]
```

### Config/pix.php Structure

```
RETURN [
    'gateway' => [
        'driver' => env('PIX_GATEWAY_DRIVER', 'mock'),
        'timeout' => env('PIX_GATEWAY_TIMEOUT', 30),
        'mock' => [
            'holder_name_prefix' => 'MOCK HOLDER for',
        ],
    ],
]
```

---

## 8. Edge Cases

1. **Already verified key** — Admin clicks verify on an already-verified key. Service throws RuntimeException with "PIX key is already verified". Controller catches and returns 422.
2. **Gateway failure (timeout/down)** — Gateway throws RuntimeException. Service does NOT update pix_key. Audit log records failure. Controller returns 502 with error message.
3. **Key not found at bank** — Gateway returns `success = false` with error code. Service throws RuntimeException. PixKey stays unverified.
4. **Unverify a key that's in active use** — A payment may be in `processing` status with this key. Unverifying breaks `hasVerifiedPixKey()` for the biker, which will block future releases and retries. This is **intentional** — the Admin is warned in the UI. No cascade is performed.
5. **Biker has multiple PIX keys** — Verifying one doesn't affect the others. `hasVerifiedPixKey()` returns true if at least one is verified. Unverifying the only verified key blocks payments again.
6. **Concurrent verification attempts** — Two admins verify the same key simultaneously. The second attempt hits the "already verified" guard and throws. No race condition.
7. **Null biker on pix_key** — Should not happen (FK constraint), but service guards against it with an existence check.
8. **PIX key with unknown key_type** — The `PixKeyType` enum restricts valid values. If somehow an invalid type exists in DB, the gateway handles it gracefully (mock returns success regardless of type).
9. **Empty key_value** — Should be blocked by validation at creation time, but `PixVerificationService` should guard against empty/null key_value before calling gateway.

---

## 9. Acceptance Criteria

### Gateway Contract & Mock Implementation

- [ ] AC-4A-01: `PixGatewayInterface` exists with three methods: `verifyKey()`, `initiatePayment()`, `checkPaymentStatus()`
- [ ] AC-4A-02: `verifyKey()` returns a structured `VerifyKeyResponse` with `success`, `account_holder_name`, `error_code`, `error_message`
- [ ] AC-4A-03: `initiatePayment()` returns a structured `PaymentResponse` with `success`, `transaction_id`, `status`, `error_code`, `error_message`
- [ ] AC-4A-04: `checkPaymentStatus()` returns a structured `PaymentResponse` (stub for Phase 4C)
- [ ] AC-4A-05: `MockPixGateway` implements `PixGatewayInterface` and is bound in the container via `PixGatewayServiceProvider`
- [ ] AC-4A-06: `MockPixGateway::verifyKey()` returns success with `"MOCK HOLDER for {keyValue}"` as `account_holder_name` for normal keys
- [ ] AC-4A-07: `MockPixGateway::verifyKey()` returns failure (`success = false`, `error_code = "KEY_NOT_FOUND"`) when `keyValue` starts with `"FAIL"`
- [ ] AC-4A-08: `MockPixGateway::verifyKey()` throws `RuntimeException("Gateway connection timeout")` when `keyValue` starts with `"ERROR"`
- [ ] AC-4A-09: `MockPixGateway::initiatePayment()` returns stub response with `status = "queued"` (Phase 4B will extend)
- [ ] AC-4A-10: `MockPixGateway::checkPaymentStatus()` returns stub response with `status = "processed"` (Phase 4C will extend)

### PixVerificationService — Verify

- [ ] AC-4A-11: `PixVerificationService::verify()` calls `gateway.verifyKey()` with the key's type and value
- [ ] AC-4A-12: On gateway success: sets `pixKey.is_verified = true`, `verified_at = now()`, `account_holder_name = response.account_holder_name`, and saves
- [ ] AC-4A-13: On gateway success: writes a `PaymentAuditLog` with `action = VerifyPix`, `transaction_ref = "pix-verify-ok-{id}-{uuid}"`, and payload containing `pix_key_id`, `biker_id`, `key_type`, `key_value`, `account_holder_name`, `verified_by`
- [ ] AC-4A-14: On gateway failure (success=false): throws `RuntimeException` with gateway error message, does NOT modify `pixKey`
- [ ] AC-4A-15: On gateway failure: writes a `PaymentAuditLog` with `action = VerifyPix`, `error_message` set, and payload containing error details
- [ ] AC-4A-16: On gateway exception (RuntimeException): does NOT modify `pixKey`, does NOT write audit log (gateway is unreachable — no partial state)
- [ ] AC-4A-17: Throws `RuntimeException` if `pixKey.is_verified` is already `true` — idempotency guard
- [ ] AC-4A-18: Throws `RuntimeException` if `pixKey.biker` does not exist

### PixVerificationService — Unverify

- [ ] AC-4A-19: `PixVerificationService::unverify()` sets `is_verified = false`, `verified_at = null`, `account_holder_name = null`, and saves
- [ ] AC-4A-20: Writes a `PaymentAuditLog` with `action = VerifyPix`, `transaction_ref = "pix-unverify-{id}-{uuid}"`, payload containing `unverified_by`
- [ ] AC-4A-21: Throws `RuntimeException` if `pixKey.is_verified` is already `false`

### Config & Container Binding

- [ ] AC-4A-22: `config/pix.php` exists with `gateway.driver` defaulting to `'mock'` from `PIX_GATEWAY_DRIVER` env var
- [ ] AC-4A-23: `PixGatewayServiceProvider` is registered and binds `PixGatewayInterface` to the configured driver implementation
- [ ] AC-4A-24: Changing `PIX_GATEWAY_DRIVER` env var changes the gateway implementation without code changes

### PaymentAuditAction Enum

- [ ] AC-4A-25: `PaymentAuditAction` enum has a new `VerifyPix = 'verify_pix'` case

### Controller — PixKeyController (Admin)

- [ ] AC-4A-26: `GET /admin/bikers/{biker}/pix-keys` returns 200 with view listing all PIX keys for the biker, showing key_type, key_value, is_verified status, account_holder_name, verified_at
- [ ] AC-4A-27: `GET /admin/bikers/{biker}/pix-keys` is protected by `auth` + `role:admin` middleware — unauthenticated returns 403, non-admin returns 403
- [ ] AC-4A-28: `POST /admin/pix-keys/{pixKey}/verify` calls `PixVerificationService::verify()` and redirects back with success message on success
- [ ] AC-4A-29: `POST /admin/pix-keys/{pixKey}/verify` redirects back with error message on `RuntimeException` (gateway failure, already verified, etc.)
- [ ] AC-4A-30: `POST /admin/pix-keys/{pixKey}/verify` is protected by `auth` + `role:admin` middleware
- [ ] AC-4A-31: `POST /admin/pix-keys/{pixKey}/unverify` calls `PixVerificationService::unverify()` and redirects back with success message
- [ ] AC-4A-32: `POST /admin/pix-keys/{pixKey}/unverify` redirects back with error message on `RuntimeException` (not verified)
- [ ] AC-4A-33: `POST /admin/pix-keys/{pixKey}/unverify` is protected by `auth` + `role:admin` middleware

### View — PIX Key Management

- [ ] AC-4A-34: Blade view `admin/bikers/pix-keys.blade.php` displays a table of PIX keys with columns: Tipo, Chave, Titular, Status (Verificada/Não verificada), Verificado em, Ações
- [ ] AC-4A-35: For unverified keys: shows a "Verificar" button (POST to `/admin/pix-keys/{id}/verify`)
- [ ] AC-4A-36: For verified keys: shows a "Desverificar" button (POST to `/admin/pix-keys/{id}/unverify`)
- [ ] AC-4A-37: View includes CSRF token on all forms
- [ ] AC-4A-38: View shows biker name and phone in header

### Integration — Existing Views Updated

- [ ] AC-4A-39: `close-review.blade.php` unverified PIX warning now includes a link to `/admin/bikers/{biker}/pix-keys` (or inline verify action) so Admin can verify without leaving context
- [ ] AC-4A-40: `payment-review.blade.php` blocked payment section includes a link to PIX key management for ineligible bikers
- [ ] AC-4A-41: `payment-status.blade.php` failed payment section includes a link to PIX key management when failure is PIX-related

### Audit Trail Integrity

- [ ] AC-4A-42: Every `PaymentAuditLog` written by `PixVerificationService` has a unique `transaction_ref` (UUID-based)
- [ ] AC-4A-43: `PaymentAuditLog.payment_id` is `null` for PIX verification events (no payment involved)
- [ ] AC-4A-44: Audit log `payload` contains `pix_key_id`, `biker_id`, `key_type`, `key_value` for traceability

### No Regressions

- [ ] AC-4A-45: All 870 existing tests continue to pass after Phase 4A implementation (0 regressions)
- [ ] AC-4A-46: Existing `hasVerifiedPixKey()` on Biker model returns correct results after verify/unverify operations
- [ ] AC-4A-47: Existing `isEligibleForRelease()` and `isEligibleForRetry()` on Payment model correctly reflect verification state changes

---

## 10. Security Considerations

- **Authorization:** All three endpoints require `auth` + `role:admin` middleware. Only admins can verify/unverify PIX keys. This is consistent with existing admin-only payment actions (Phase 3A/3B/3C).
- **Input Validation:** `VerifyPixKeyRequest` is an empty form request for now (no user input — the PIX key ID comes from the route). Future phases may add confirmation fields. `PixKeyController` validates that the `pixKey` route parameter resolves to an actual `PixKey` record (implicit via route-model binding).
- **CSRF Protection:** All POST endpoints use `@csrf` in Blade forms, protected by Laravel's `VerifyCsrfToken` middleware.
- **Container Compliance:** All code runs inside the devcontainer. `MockPixGateway` requires no external network access. The gateway interface is designed so future real implementations can make HTTP calls, but that is outside the container's current scope.
- **Financial Safety:** This phase does not move money or modify payment amounts. It only changes `pix_keys` metadata. However, unverify is a sensitive operation — it can block payments. The audit trail captures who unverifed and when.
- **Gateway Isolation:** The gateway implementation is resolved from the container, not hardcoded. Switching to a real provider only requires a new class + env config change. No service code changes.

---

## 11. Notes for Future Phases

- **Phase 4B** will extend `MockPixGateway::initiatePayment()` to be called from `PaymentReleaseService` after a payment transitions to `processing`.
- **Phase 4C** will extend `MockPixGateway::checkPaymentStatus()` and add a webhook controller for async bank callbacks.
- **Future:** Add a `PixKeyType` enum validation rule in `VerifyPixKeyRequest` if Admin-submitted key creation is ever allowed.
- **Future:** Add rate limiting on verify/unverify endpoints to prevent abuse.
