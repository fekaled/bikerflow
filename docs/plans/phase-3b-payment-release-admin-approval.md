# Plan: Phase 3B — Payment Release & Admin Approval + M-01 Fix

**Task ID:** Phase-3B
**Date:** 2026-05-16
**Planner Version:** 1.0
**Complexity:** Complex

---

## 1. Objective

Implement the Admin payment release workflow for the Payout Engine. After a shift is closed and Payment rows are created (Phase 3A), the Admin reviews all pending payments per shift, sees eligibility status (PIX verified, User account linked), and explicitly releases eligible payments. Released payments transition from `pending` to `processing`. When all payments for a shift are released (or beyond), the shift auto-transitions from `closed` to `approved`. This plan also fixes M-01 — duplicate warning badges in `close-review.blade.php`.

---

## 2. Source References

### User Stories
- No direct US-XX match — infrastructure phase enabling BR-03 enforcement and BR-04 granularity

### Business Rules
- **BR-02 (PIX Verification):** Payments blocked if biker has no verified PIX key. Full enforcement (block, not warning)
- **BR-03 (Manual Release):** Admin must explicitly release each eligible payment. No automated payments
- **BR-04 (Granular Failure):** Each payment is independent — releasing one doesn't affect others

### ADR Decisions
- **ADR-005 D1:** Admin-only release (same as close)
- **ADR-005 D4:** Bikers must have User accounts to be paid — now enforced as a block, not just a warning

### PRD Sections
- Section 2C: Company Manager — "Reviews 'Closed' shifts, verifies margins, and clicks 'Release Payment'"
- Section 4: BR-02, BR-03, BR-04

### Tech Doc Sections
- Section 3: Business Logic & Formulas
- Section 5: Security & Guardrails (PIX Verification, Audit Logging)

### Related Plans
- `docs/plans/phase-3a-shift-close-payout-calculation.md` — Phase 3A (completed)
- `docs/adr/005-phase3-prerequisite-decisions.md` — Prerequisite decisions D1–D5

---

## 3. Scope

### In Scope
1. **Payment Review View** — Admin sees all pending payments for a closed shift, with payout amounts, revenue, and eligibility status (PIX verified, User account linked)
2. **PIX Verification Gate** — Payments for bikers without verified PIX keys are blocked from release (BR-02 full enforcement)
3. **Biker User Account Gate** — Payments for bikers without linked User accounts are blocked from release (ADR-005 D4 enforcement)
4. **Individual Payment Release** — Admin explicitly releases a single eligible payment. Payment transitions `pending → processing`. `released_by` and `released_at` are set
5. **Batch Payment Release** — Admin releases all eligible payments for a shift in one action
6. **Shift Auto-Transition** — When all payments for a shift are `processing` (or beyond), shift transitions `closed → approved`
7. **PaymentReleaseService** — Encapsulates release logic: eligibility checks, status transition, audit log creation, shift auto-transition
8. **ShiftController Updates** — Add `reviewPayments` (GET) and `releasePayment` (POST) and `releaseAllPayments` (POST) actions
9. **M-01 Fix** — Remove duplicate warning badges in `close-review.blade.php`

### Out of Scope
1. Actual PIX payment execution (`processing → paid`/`failed`) — Phase 3C
2. Payment failure handling and retry logic — Phase 3C
3. PIX key management UI — standalone feature
4. US-03 Margin Dashboard — Phase 5
5. US-04 Biker notifications — Phase 5
6. Biker onboarding flow / self-registration — deferred
7. Audit log UI — deferred
8. Reverting a released payment back to pending — deferred (edge case for Phase 3C)

### Open Questions

_None — all resolved by task description and PRD._

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Already enforced in Shift model. No change needed. |
| BR-02 PIX Verification | **Yes (full enforcement)** | Payments for bikers without at least one `is_verified = true` PIX key are **blocked** from release. This is a hard block — the release action must refuse and return an error. |
| BR-03 Manual Release | **Yes (core rule)** | No automated payments. Admin must explicitly click "Release" per payment or "Release All Eligible". Each release sets `released_by` and `released_at` on the Payment. |
| BR-04 Granular Failure | **Yes (core rule)** | Releasing Payment A does not affect Payment B. Ineligible payments are skipped, not errored. Batch release releases only eligible ones. |
| BR-05 Last Minute Biker | No | Already enforced. No change needed. |
| BR-06 Payment Retries | No | No retries in this phase. Release is not a retry — it's a one-time transition. |

---

## 5. Schema Changes

### New Tables

No new tables.

### Modified Tables

No modifications — all required columns already exist:
- `payments.released_by` (unsignedBigInteger, nullable) — already in migration `2026_05_14_000006`
- `payments.released_at` (timestamp, nullable) — already in migration `2026_05_14_000006`
- `payments.status` (string 20, default 'pending') — already in migration `2026_05_14_000006`
- `shifts.status` — already has `approved` in `ShiftStatus` enum

### Indexes

No new indexes.

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| payments.amount | payments | DECIMAL(12,2) | Yes — read-only in this phase |
| payments.revenue | payments | DECIMAL(12,2) | Yes — read-only in this phase |
| shift_bikers.biker_rate | shift_bikers | DECIMAL(12,2) | Not touched in this phase |
| shift_bikers.base_fee | shift_bikers | DECIMAL(12,2) | Not touched in this phase |
| shifts.restaurant_rate | shifts | DECIMAL(12,2) | Not touched in this phase |

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Service | `app/Services/PaymentReleaseService.php` | Encapsulates payment release logic: eligibility checks, status transition, audit log, shift auto-transition |
| Request | `app/Http/Requests/ReleasePaymentRequest.php` | Validates release requests (shift is closed, payment is pending, payment belongs to shift) |
| View | `resources/views/shifts/payment-review.blade.php` | Payment review page: list of payments with eligibility status, release buttons |
| Test | `tests/Unit/Services/PaymentReleaseServiceTest.php` | Unit tests for release logic, eligibility checks, shift auto-transition |
| Test | `tests/Feature/Controllers/PaymentReleaseControllerTest.php` | Feature tests for the payment review and release endpoints |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | Add `reviewPayments` (GET), `releasePayment` (POST), `releaseAllPayments` (POST) methods |
| Route | `routes/web.php` | Add 3 new admin routes for payment review/release |
| Policy | `app/Policies/ShiftPolicy.php` | Add `reviewPayments` and `releasePayment` methods (Admin-only) |
| Model | `app/Models/Payment.php` | Add `releasedBy()` relationship, add `isEligibleForRelease()` helper, add `releasedByUser` BelongsTo relationship |
| Model | `app/Models/Shift.php` | Add `allPaymentsReleased()` helper method |
| Model | `app/Models/Biker.php` | Add `hasVerifiedPixKey()` and `hasUserAccount()` helper methods |
| View | `resources/views/shifts/show.blade.php` | Add "Revisar Pagamentos" button for closed shifts; update biker-assignments partial to show payment status for closed/approved shifts |
| View | `resources/views/shifts/partials/biker-assignments.blade.php` | Update `$isClosed` logic to also include `approved` status; show payment release info |
| View | `resources/views/shifts/close-review.blade.php` | **M-01 Fix:** Remove duplicate `@if(!$item['hasUser'])` and `@if(!$item['hasVerifiedPixKey'])` blocks |

---

## 7. Pseudocode

### PaymentReleaseService — Core Logic

```
CLASS PaymentReleaseService:

    /**
     * Get payment review data for a closed shift.
     * Loads all payments with their shiftBiker → biker → pixKeys + user relationships.
     * Determines eligibility per payment.
     */
    METHOD getPaymentReviewData(Shift shift):
        ASSERT shift.status IN [ShiftStatus::Closed, ShiftStatus::Approved]

        shift.load([
            'shiftBikers.biker.pixKeys',
            'shiftBikers.biker' → for user check,
            'shiftBikers.payment'
        ])

        paymentItems = []
        totalPending = '0.00'
        totalProcessing = '0.00'
        eligibleCount = 0
        ineligibleCount = 0

        FOR EACH shiftBiker IN shift.shiftBikers:
            payment = shiftBiker.payment
            biker = shiftBiker.biker

            // Skip if no payment exists (shouldn't happen, but defensive)
            IF payment IS NULL:
                CONTINUE

            // Eligibility checks
            hasUser = User::where('biker_id', biker.id).exists()
            hasVerifiedPixKey = biker.pixKeys()
                .where('is_verified', true).exists()

            isEligible = hasUser AND hasVerifiedPixKey
                AND payment.status === PaymentStatus::Pending

            blockReasons = []
            IF NOT hasUser:
                blockReasons.append("Entregador sem conta de usuário vinculada")
            IF NOT hasVerifiedPixKey:
                blockReasons.append("Entregador sem chave PIX verificada")
            IF payment.status !== PaymentStatus::Pending:
                blockReasons.append("Pagamento não está pendente (status: {payment.status})")

            IF payment.status === PaymentStatus::Pending:
                totalPending = bcadd(totalPending, payment.amount, 2)
            IF payment.status === PaymentStatus::Processing:
                totalProcessing = bcadd(totalProcessing, payment.amount, 2)

            IF isEligible:
                eligibleCount++
            ELSE IF payment.status === PaymentStatus::Pending:
                ineligibleCount++

            paymentItems.append({
                shiftBiker,
                biker,
                payment,
                hasUser,
                hasVerifiedPixKey,
                isEligible,
                blockReasons
            })

        RETURN {
            shift,
            paymentItems,
            totalPending,
            totalProcessing,
            eligibleCount,
            ineligibleCount,
            allReleased: shift.shiftBikers
                .where('payment.status', '!=', PaymentStatus::Pending)
                ->count() === shift.shiftBikers.count()
        }


    /**
     * Release a single payment.
     * BR-02: Block if no verified PIX key.
     * ADR-005 D4: Block if no user account.
     * BR-03: Explicit admin action required.
     */
    METHOD releasePayment(Payment payment, User admin):
        // Pre-condition: payment must be pending
        IF payment.status !== PaymentStatus::Pending:
            THROW RuntimeException("Payment is not pending. Status: {payment.status}")

        biker = payment.shiftBiker.biker

        // BR-02: PIX verification gate
        hasVerifiedPixKey = biker.pixKeys()
            .where('is_verified', true).exists()
        IF NOT hasVerifiedPixKey:
            THROW RuntimeException("Biker has no verified PIX key. Payment blocked.")

        // ADR-005 D4: User account gate
        hasUser = User::where('biker_id', biker.id).exists()
        IF NOT hasUser:
            THROW RuntimeException("Biker has no linked User account. Payment blocked.")

        // Transition payment status
        payment.status = PaymentStatus::Processing
        payment.released_by = admin.id
        payment.released_at = now()
        payment.save()

        // Create audit log entry (BR-06 pattern)
        PaymentAuditLog::create([
            payment_id: payment.id,
            action: PaymentAuditAction::Release,
            transaction_ref: "release-{payment.id}-{timestamp}",
            payload: {
                released_by: admin.id,
                released_at: now(),
                amount: payment.amount,
                biker_id: biker.id
            }
        ])

        // Check if all payments for this shift are now released
        this.checkAndTransitionShiftToApproved(payment.shiftBiker.shift)

        RETURN payment


    /**
     * Batch release all eligible payments for a shift.
     * BR-04: Each payment is independent — one failure doesn't stop others.
     */
    METHOD releaseAllEligiblePayments(Shift shift, User admin):
        ASSERT shift.status === ShiftStatus::Closed

        results = { released: [], blocked: [] }

        shift.load('shiftBikers.payment', 'shiftBikers.biker.pixKeys')

        FOR EACH shiftBiker IN shift.shiftBikers:
            payment = shiftBiker.payment
            IF payment IS NULL OR payment.status !== PaymentStatus::Pending:
                CONTINUE

            TRY:
                this.releasePayment(payment, admin)
                results.released.append(payment.id)
            CATCH RuntimeException AS e:
                results.blocked.append({
                    payment_id: payment.id,
                    biker: shiftBiker.biker.name,
                    reason: e.getMessage()
                })

        RETURN results


    /**
     * Auto-transition shift to Approved when all payments are released.
     */
    METHOD checkAndTransitionShiftToApproved(Shift shift):
        shift.refresh()
        shift.load('shiftBikers.payment')

        // Check: every shiftBiker has a payment in processing/paid/failed (not pending)
        allReleased = shift.shiftBikers.every(
            fn(sb) => sb.payment
                && sb.payment.status !== PaymentStatus::Pending
        )

        IF allReleased AND shift.status === ShiftStatus::Closed:
            shift.status = ShiftStatus::Approved
            shift.save()
```

### Biker Model — Eligibility Helpers

```
// In Biker model:

METHOD hasVerifiedPixKey():
    RETURN this.pixKeys().where('is_verified', true).exists()

METHOD hasUserAccount():
    RETURN User::where('biker_id', this.id).exists()

METHOD user():
    RETURN this.hasOne(User::class, 'biker_id')
```

### Payment Model — Additions

```
// In Payment model:

METHOD releasedByUser():
    RETURN this.belongsTo(User::class, 'released_by')

METHOD isEligibleForRelease():
    IF this.status !== PaymentStatus::Pending:
        RETURN false

    biker = this.shiftBiker.biker
    IF NOT biker.hasVerifiedPixKey():
        RETURN false
    IF NOT biker.hasUserAccount():
        RETURN false

    RETURN true

SCOPE forShift(Builder query, int shiftId):
    RETURN query.whereHas('shiftBiker', fn(q) => q.where('shift_id', shiftId))
```

### Shift Model — Helper

```
// In Shift model:

METHOD allPaymentsReleased():
    this.load('shiftBikers.payment')
    RETURN this.shiftBikers.every(
        fn(sb) => sb.payment && sb.payment.status !== PaymentStatus::Pending
    )
```

### ShiftController — New Methods

```
// In ShiftController:

/**
 * Phase 3B: Show payment review page for a closed/approved shift (GET).
 */
METHOD reviewPayments(Request request, Shift shift):
    this.authorize('reviewPayments', shift)

    IF shift.status NOT IN [ShiftStatus::Closed, ShiftStatus::Approved]:
        RETURN redirect()->route('shifts.show', shift)
            .with('error', 'Somente turnos encerrados podem ter pagamentos revisados.')

    reviewData = app(PaymentReleaseService).getPaymentReviewData(shift)

    RETURN view('shifts.payment-review', reviewData)


/**
 * Phase 3B: Release a single payment (POST).
 */
METHOD releasePayment(ReleasePaymentRequest request, Shift shift, Payment payment):
    TRY:
        app(PaymentReleaseService).releasePayment(payment, request.user())

        RETURN redirect()->route('shifts.payments.review', shift)
            .with('success', 'Pagamento liberado com sucesso.')
    CATCH RuntimeException AS e:
        RETURN back()->with('error', e.getMessage())


/**
 * Phase 3B: Batch release all eligible payments (POST).
 */
METHOD releaseAllPayments(ReleasePaymentRequest request, Shift shift):
    IF shift.status !== ShiftStatus::Closed:
        RETURN back()->with('error', 'Somente turnos encerrados podem ter pagamentos liberados.')

    results = app(PaymentReleaseService).releaseAllEligiblePayments(shift, request.user())

    message = "{count(results.released)} pagamentos liberados."
    IF count(results.blocked) > 0:
        message += " {count(results.blocked)} pagamentos bloqueados."

    RETURN redirect()->route('shifts.payments.review', shift)
        .with('success', message)
```

### ReleasePaymentRequest — Validation

```
CLASS ReleasePaymentRequest:

    METHOD authorize():
        shift = this.route('shift')
        RETURN this.user().can('releasePayment', shift)

    METHOD rules():
        RETURN []  // No input fields needed for single release; payment validated in service
```

### ShiftPolicy — New Methods

```
// In ShiftPolicy:

/**
 * Phase 3B: Only Admin can review payments for a shift.
 */
METHOD reviewPayments(User user, Shift shift):
    RETURN user.isAdmin()

/**
 * Phase 3B: Only Admin can release payments for a shift.
 */
METHOD releasePayment(User user, Shift shift):
    RETURN user.isAdmin()
```

### State Transitions

```
Payment: pending ──(Admin releases)──▶ processing
                                              │
                                     released_by = admin.id
                                     released_at = now()
                                     Audit log created

Shift: closed ──(all payments released)──▶ approved
                       │
                       │  If any payment still pending
                       └── stays closed
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware | Description |
|--------|-----|-------------------|------|------------|-------------|
| GET | `shifts/{shift}/payments/review` | `ShiftController@reviewPayments` | Admin | `auth`, `role:admin` | Show payment review page with eligibility status |
| POST | `shifts/{shift}/payments/{payment}/release` | `ShiftController@releasePayment` | Admin | `auth`, `role:admin` | Release a single eligible payment |
| POST | `shifts/{shift}/payments/release-all` | `ShiftController@releaseAllPayments` | Admin | `auth`, `role:admin` | Batch release all eligible payments |

> **Note:** All three routes are inside the existing `Route::middleware(['auth', 'role:admin'])` group in `routes/web.php`.

### M-01 Fix — close-review.blade.php

**Bug:** In the `<td>` for alerts, the `@foreach($item['warnings'] as $warning)` loop renders all warning badges. Then two separate `@if` blocks render the same warnings again:
- `@if(!$item['hasUser'])` → "Sem conta de usuário"
- `@if(!$item['hasVerifiedPixKey'])` → "Sem chave PIX verificada"

**Fix:** Remove the two `@if` blocks after the `@endforeach`. The `@foreach` loop is the single source of truth — it already renders all warnings from the `$item['warnings']` array.

**Before (buggy):**
```
@foreach($item['warnings'] as $warning)
    <span ...>{{ $warning }}</span>
@endforeach

@if(!$item['hasUser'])           ← DUPLICATE
    <span ...>Sem conta de usuário</span>
@endif

@if(!$item['hasVerifiedPixKey']) ← DUPLICATE
    <span ...>Sem chave PIX verificada</span>
@endif
```

**After (fixed):**
```
@foreach($item['warnings'] as $warning)
    <span ...>{{ $warning }}</span>
@endforeach
```

---

## 8. Edge Cases

1. **Payment already in `processing` status** — `releasePayment` must check and refuse. Status guard in service throws exception.
2. **Payment in `paid` or `failed` status** — Same as above. Only `pending` payments can be released.
3. **Shift with all payments already released** — GET review page still works (shows all as `processing`/`paid`). POST release-all is a no-op (nothing to release). Shift may already be `approved`.
4. **Shift with some payments released, some blocked** — GET review page shows mixed status. Shift stays `closed`. Admin can see which are blocked and why.
5. **Biker deactivated after shift close** — Not relevant for release. Release checks PIX and User, not `biker.active`. Per ADR-005 D3, inactive bikers are still paid for past shifts.
6. **Biker's PIX key revoked after shift close** — Release checks `is_verified` at release time. If PIX was verified at close time but revoked before release, the payment is blocked. This is correct behavior — prevents paying to invalid PIX keys.
7. **Admin releases payment, then biker's User account is deleted** — Payment is already `processing`. The `released_by` and `released_at` are set. The actual PIX execution (Phase 3C) will handle the failure case.
8. **Concurrent release attempts** — Two admins click "Release" simultaneously on same payment. First wins (status changes to `processing`). Second gets RuntimeException ("Payment is not pending"). No double-release possible.
9. **Shift with zero bikers** — No payments. GET review page shows empty state. No payments to release. Shift cannot transition to `approved` (no payments exist). **Open Question: Should a shift with zero bikers auto-transition to `approved`?** Assumption: Yes — if there are no payments, there's nothing to release, so all payments (zero) are "released" by vacuous truth. The `allPaymentsReleased()` method should return `true` for an empty collection.
10. **Shift with zero-trip bikers** — Payment exists with `amount = '0.00'`. This payment should still go through the release flow. It's a valid payment (even if zero amount). It still needs PIX verification and User account check.
11. **Batch release with all eligible** — All released, shift auto-transitions to `approved`.
12. **Batch release with none eligible** — No payments released. Admin sees all blocked with reasons. Shift stays `closed`.
13. **Batch release with some eligible** — Eligible ones released, ineligible skipped. Results returned show both lists. Shift may or may not auto-transition depending on whether the released ones were the last pending ones.
14. **Shift already `approved`** — GET review still works (read-only view of payments). POST release for individual payments still works if any somehow remain pending (shouldn't happen, but defensive). POST release-all is a no-op.
15. **Payment amount is `0.00`** — Zero-amount payments can still be released. They will transition to `processing` and eventually `paid`. The PIX system (Phase 3C) should handle zero-amount payments gracefully (possibly skip actual transfer).

---

## 9. Acceptance Criteria

### Payment Review View (GET)

- [ ] AC-3B-01: GET `shifts/{shift}/payments/review` returns 200 for Admin on a closed shift
- [ ] AC-3B-02: GET `shifts/{shift}/payments/review` returns 200 for Admin on an approved shift (read-only)
- [ ] AC-3B-03: GET `shifts/{shift}/payments/review` redirects non-Admin users with 403
- [ ] AC-3B-04: GET `shifts/{shift}/payments/review` redirects to `shifts.show` with error if shift is not `closed` or `approved`
- [ ] AC-3B-05: Review view displays each payment's biker name, amount, revenue, and current status
- [ ] AC-3B-06: Review view displays eligibility status per payment: "PIX verificada ✓" or "PIX não verificada ✗"
- [ ] AC-3B-07: Review view displays eligibility status per payment: "Conta vinculada ✓" or "Sem conta ✗"
- [ ] AC-3B-08: Review view shows "Liberar" button ONLY for eligible payments (pending + has verified PIX + has user account)
- [ ] AC-3B-09: Review view shows block reasons for ineligible payments (e.g., "Sem chave PIX verificada")
- [ ] AC-3B-10: Review view shows "Liberar Todos Elegíveis" button (visible when at least one eligible payment exists)
- [ ] AC-3B-11: Review view displays total pending amount and total released (processing) amount
- [ ] AC-3B-12: Review view shows empty state when shift has no bikers/payments
- [ ] AC-3B-13: Review view shows payment status badge (pending/processing) with appropriate colors

### Individual Payment Release (POST)

- [ ] AC-3B-14: POST `shifts/{shift}/payments/{payment}/release` transitions payment from `pending` to `processing`
- [ ] AC-3B-15: Release sets `released_by` to the authenticated Admin's user ID
- [ ] AC-3B-16: Release sets `released_at` to current timestamp
- [ ] AC-3B-17: Release creates a `PaymentAuditLog` entry with `action = 'release'`
- [ ] AC-3B-18: Release blocked if biker has no verified PIX key (BR-02) — returns error message
- [ ] AC-3B-19: Release blocked if biker has no linked User account (ADR-005 D4) — returns error message
- [ ] AC-3B-20: Release blocked if payment is not in `pending` status — returns error message
- [ ] AC-3B-21: Release redirects non-Admin users with 403
- [ ] AC-3B-22: Release validates that payment belongs to the specified shift — returns 404/error if mismatch
- [ ] AC-3B-23: Successful release redirects back to payment review page with success message

### Batch Release (POST)

- [ ] AC-3B-24: POST `shifts/{shift}/payments/release-all` releases all eligible payments (pending + verified PIX + user account)
- [ ] AC-3B-25: Batch release skips ineligible payments without error (BR-04 granularity)
- [ ] AC-3B-26: Batch release returns summary: count released + count blocked with reasons
- [ ] AC-3B-27: Batch release only works on `closed` shifts — returns error for other statuses
- [ ] AC-3B-28: Batch release is idempotent — calling twice doesn't error, just releases nothing the second time

### Shift Auto-Transition

- [ ] AC-3B-29: When all payments for a shift reach `processing` status (or beyond), shift auto-transitions from `closed` to `approved`
- [ ] AC-3B-30: Shift transition happens atomically after the last payment is released
- [ ] AC-3B-31: Shift with zero bikers/payments auto-transitions to `approved` when reviewed (vacuous truth — no pending payments exist)
- [ ] AC-3B-32: Shift with some blocked payments stays `closed` even if all non-blocked payments are released
- [ ] AC-3B-33: When shift reaches `approved` status, the review page still shows all payment details (read-only view)

### Shift Show Page Updates

- [ ] AC-3B-34: For closed shifts, `shifts.show` displays a "Revisar Pagamentos" button linking to `shifts.payments.review`
- [ ] AC-3B-35: For approved shifts, `shifts.show` shows "Aprovado" status with link to payment review
- [ ] AC-3B-36: For closed/approved shifts, biker-assignments partial shows payment status (pending/processing) per biker

### M-01 Fix

- [ ] AC-3B-37: `close-review.blade.php` renders each warning badge exactly once (no duplicates)
- [ ] AC-3B-38: Warning badges for "sem conta de usuário" and "sem chave PIX verificada" come exclusively from the `@foreach($item['warnings'])` loop
- [ ] AC-3B-39: The duplicate `@if(!$item['hasUser'])` and `@if(!$item['hasVerifiedPixKey'])` blocks are removed

### Financial Integrity

- [ ] AC-3B-40: Payment amounts are never modified during release — only status, released_by, and released_at change
- [ ] AC-3B-41: Revenue values are never modified during release
- [ ] AC-3B-42: All monetary values remain DECIMAL(12,2) with exactly 2 decimal places

### Audit Trail

- [ ] AC-3B-43: Each successful release creates exactly one `PaymentAuditLog` row with `action = 'release'`
- [ ] AC-3B-44: Audit log `transaction_ref` is unique per release action
- [ ] AC-3B-45: Audit log `payload` contains `released_by`, `released_at`, `amount`, and `biker_id`
- [ ] AC-3B-46: Failed release attempts (blocked by eligibility) do NOT create audit log entries

---

## 10. Security Considerations

- **Authorization:** All payment release routes are within the existing `role:admin` middleware group. Policy methods `reviewPayments` and `releasePayment` enforce Admin-only access. Same pattern as Phase 3A close flow.
- **Input Validation:** `ReleasePaymentRequest` validates that the user is Admin and the payment belongs to the shift. The service layer validates payment status and eligibility independently — defense in depth.
- **Container Compliance:** All operations occur within `/workspaces/bikerflow`. No external API calls. No file system access outside the project.
- **Financial Safety:**
  - No monetary values are modified during release — only status changes and audit metadata.
  - BCMath is not needed in the release flow (no calculations), but all reads of monetary values maintain `decimal:2` casting.
  - Double-release prevention: status guard (`pending` → `processing`) is atomic. Concurrent requests race to update; only the first succeeds.
  - Audit trail: every release is logged with `PaymentAuditLog` for traceability (BR-06 pattern).
- **Route-Model Binding:** Payment release route uses `{payment}` parameter. The Developer MUST verify that the payment belongs to the specified shift (`shift_id` check) to prevent cross-shift payment manipulation.
- **Idempotency:** Releasing an already-`processing` payment is a no-op at the service level (throws exception caught by controller, returns friendly error). Batch release skips non-pending payments silently.

---

## Appendix: Files to Create — Detailed Contents

### `app/Services/PaymentReleaseService.php`

Methods:
1. `getPaymentReviewData(Shift $shift): array` — Returns structured data for the payment review view
2. `releasePayment(Payment $payment, User $admin): Payment` — Releases a single payment with all guard checks
3. `releaseAllEligiblePayments(Shift $shift, User $admin): array` — Batch release with per-payment independence
4. `checkAndTransitionShiftToApproved(Shift $shift): void` — Auto-transition logic

Dependencies: `PayoutService` (not used), `RevenueService` (not used) — this service does NOT recalculate amounts.

### `resources/views/shifts/payment-review.blade.php`

Structure:
- Header: "Revisão de Pagamentos — Turno #ID"
- Shift info card: Restaurant name, shift status badge, total pending/processing amounts
- Payment table: columns = Biker Name | Trips | Amount | Revenue | PIX Status | Account Status | Payment Status | Actions
- Each row: eligibility indicators (green ✓ / red ✗), block reasons if ineligible, "Liberar" button if eligible
- Footer: "Liberar Todos Elegíveis" button, back link to shift show
- Status badges: `pending` = yellow, `processing` = blue, `paid` = green, `failed` = red
- If shift is `approved`: all release buttons disabled, "Turno aprovado" banner shown

### `tests/Unit/Services/PaymentReleaseServiceTest.php`

Test cases:
1. Release eligible payment (has user + verified PIX + pending) → status becomes `processing`
2. Release payment without verified PIX → throws exception
3. Release payment without user account → throws exception
4. Release payment already in `processing` → throws exception
5. Release payment in `paid` status → throws exception
6. Release sets `released_by` and `released_at`
7. Release creates audit log entry
8. Batch release: mixed eligible/ineligible → only eligible released
9. Batch release: all eligible → all released
10. Batch release: none eligible → none released
11. Shift auto-transition: all payments released → shift becomes `approved`
12. Shift auto-transition: some payments still pending → shift stays `closed`
13. Shift auto-transition: zero bikers → shift auto-transitions to `approved`
14. Zero-amount payment release → succeeds (valid business case)

### `tests/Feature/Controllers/PaymentReleaseControllerTest.php`

Test cases:
1. GET review returns 200 for Admin on closed shift
2. GET review returns 200 for Admin on approved shift
3. GET review returns 403 for non-Admin
4. GET review redirects for non-closed/non-approved shift
5. POST release single payment — success → redirects with success message
6. POST release single payment — ineligible → redirects with error message
7. POST release single payment — 403 for non-Admin
8. POST release single payment — wrong shift → 404/error
9. POST release all — success → redirects with summary message
10. POST release all — 403 for non-Admin
11. POST release all — non-closed shift → error
