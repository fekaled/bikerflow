# Plan: Phase 3C — Payment Failure Handling & Retry

**Task ID:** Phase-3C
**Date:** 2026-05-16
**Planner Version:** 1.0
**Complexity:** Complex

---

## 1. Objective

Complete the Payout Engine state machine by implementing the final payment transitions: `processing → paid` (success), `processing → failed` (failure with reason), and `failed → processing` (Admin-initiated retry). Each failed payment is independent — a single failure must never regress the parent shift, and successful settlements must auto-reconcile the shift from `approved` to `paid` only when **every** payment in the shift is `paid`. This phase is Admin-only, fully audited, idempotent, and lives entirely inside the Dev Container (no real PIX gateway integration — that comes later).

---

## 2. Source References

### User Stories
- No direct US-XX match — settlement infrastructure phase closing the BR-04 and BR-06 loop.

### Business Rules
- **BR-02 (PIX Verification):** Re-checked on retry — a payment that became ineligible (e.g., PIX revoked) must be re-blocked.
- **BR-03 (Manual Release):** Reaffirmed — settlement transitions are explicit Admin actions, never automated.
- **BR-04 (Granular Failure):** Core to this phase. Each payment fails independently. One failed payment must not regress the shift from `approved` back to `closed`, nor poison sibling payments.
- **BR-06 (Payment Retries):** Core to this phase. Failed payments can be retried. Every attempt — success, failure, retry — gets a unique audit log entry with `transaction_ref`.

### ADR Decisions
- **ADR-005 D1:** Admin-only for all settlement actions (mirror Phase 3B).
- **ADR-005 D4:** Bikers must still have a linked User account at retry time. Re-evaluated on every retry.

### PRD Sections
- Section 2C: Company Manager — settlement and reconciliation responsibility.
- Section 4: BR-02, BR-03, BR-04, BR-06 (full text).

### Tech Doc Sections
- Section 3: Business Logic & Formulas (read-only here — no recalculation).
- Section 5: Security & Guardrails (PIX Verification, Audit Logging, Idempotency).

### Related Plans
- `docs/plans/phase-3a-shift-close-payout-calculation.md` — Phase 3A (Validated) — creates `Payment` rows in `pending`.
- `docs/plans/phase-3b-payment-release-admin-approval.md` — Phase 3B (Implemented) — transitions `pending → processing` and `closed → approved`.

---

## 3. Scope

### In Scope
1. **Mark Paid** — Admin marks a `processing` payment as `paid`. Sets `paid_at = now()`. Writes a `PaymentAuditLog` row with `action = succeed`.
2. **Mark Failed** — Admin marks a `processing` payment as `failed`. Sets `failed_at = now()` and `failure_reason` from input (free-form). Writes a `PaymentAuditLog` row with `action = fail` (includes `error_message`).
3. **Retry (BR-06)** — Admin retries a `failed` payment. Transitions `failed → processing`. Increments `retry_count`. Re-validates eligibility (BR-02 verified PIX + ADR-005 D4 linked User account) — re-blocks if no longer eligible. Clears `failed_at` and `failure_reason`. Writes a `PaymentAuditLog` row with `action = retry`. **Hard cap: when `retry_count >= 3`, retry is refused — the payment is permanently failed and the Admin is warned to intervene manually (e.g., bank transfer, contact biker).**
4. **Shift Reconciliation** — When **every** payment for a shift reaches `Paid`, the shift auto-transitions `approved → paid` (terminal). If any payment is still `processing` or `failed`, the shift remains `approved`. **BR-04: a single failed payment never regresses the shift.**
5. **Admin UI — Payment Status Dashboard** — Per-shift dashboard listing payments grouped by status:
   - `processing` payments → "Marcar como Pago" + "Marcar como Falha" buttons.
   - `failed` payments → "Tentar Novamente" button + visible `failure_reason`.
   - `paid` payments → read-only with `paid_at` timestamp.
   - Reuse `payment-review.blade.php` styling; either extend it conditionally or create a sibling `payment-status.blade.php` (Developer's call — pick whichever yields less duplication).
6. **PaymentSettlementService** — New service encapsulating `markPaid`, `markFailed`, `retry`, and `reconcileShiftStatus`. Strictly guards state transitions at the service layer; controllers only orchestrate.
7. **Idempotency** — Marking an already-`paid` payment as paid returns HTTP 422 with no duplicate audit. Retrying a non-`failed` payment returns HTTP 422. Marking a non-`processing` payment failed returns HTTP 422.
8. **Admin-only** (ADR-005 D1) — All actions guarded by `ShiftPolicy` + `role:admin` middleware.

### Out of Scope
1. Real PIX gateway integration (FitBank, Stark Bank) and webhook ingestion.
2. Automated/scheduled payment execution.
3. Biker-facing notifications on success/failure (US-04, Phase 5).
4. Reversing a `paid` payment back to any earlier status — `paid` is terminal.
5. **Bulk retry** — Phase 3C scope is single-payment retry only. Bulk retry is a future enhancement; leave a TODO comment in the service.
6. US-03 Margin Dashboard.
7. Hard retry cap at 3 — payment permanently fails and Admin is warned to make a decision (OQ-3C-01 resolved).
8. `failure_reason` is free-form string — no preset reason picker (OQ-3C-02 resolved).

### Open Questions — RESOLVED by Product Owner (2026-05-17)
- **OQ-3C-01: Maximum `retry_count` cap?** ✅ **RESOLVED: Hard cap at 3.** When `retry_count` reaches 3, the payment MUST permanently fail and the Admin must be warned to make a decision (e.g., manual bank transfer, contact biker, etc.). The payment transitions to a terminal `failed` state and cannot be retried again. The service enforces this by refusing retry when `retry_count >= 3` and returning a specific error message directing the Admin to intervene.
- **OQ-3C-02: Free-form vs. enum `failure_reason`?** ✅ **RESOLVED: Free-form for now.** `failure_reason` remains `string(500)` free-form. Normalize to a preset enum in a later phase if real failure patterns emerge.
- **OQ-3C-03: Re-confirm PIX key on retry?** ✅ **RESOLVED: Just re-run.** Retry calls `Payment::isEligibleForRelease()` to re-check eligibility. No additional PIX re-confirmation flow needed.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Shift workflow rules already enforced upstream. No change. |
| BR-02 PIX Verification | **Yes (re-check on retry)** | `retry()` MUST call `Payment::isEligibleForRelease()` and refuse if the biker no longer has a verified PIX key. The original release passed BR-02; retry must re-verify because state can drift. |
| BR-03 Manual Release | **Yes (reaffirmed)** | Mark Paid, Mark Failed, and Retry are all explicit Admin actions. No automated/scheduled state transitions. |
| BR-04 Granular Failure | **Yes (core)** | Each payment transitions independently. A failed payment NEVER regresses sibling payments or the shift. Failure of one payment does not block success of another. |
| BR-05 Last Minute Biker | No | Already enforced upstream at shift open/close. No change. |
| BR-06 Payment Retries | **Yes (core)** | `failed → processing` retry path. Each retry increments `retry_count` and writes a unique `PaymentAuditLog` row with `action = retry`. Every settlement attempt is logged. |

---

## 5. Schema Changes

### New Tables
None.

### Modified Tables — `payments`

New migration: `database/migrations/2026_05_17_000001_add_failure_columns_to_payments_table.php`

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `failed_at` | `timestamp` | yes | `null` | Set when transitioning `processing → failed`. Cleared on retry. |
| `failure_reason` | `string(500)` | yes | `null` | Free-form. Set on mark-failed, cleared on retry. |
| `retry_count` | `unsignedInteger` | no | `0` | Incremented on each successful retry transition. |

**Migration up():**
```
Schema::table('payments', function (Blueprint $table) {
    $table->timestamp('failed_at')->nullable()->after('paid_at');
    $table->string('failure_reason', 500)->nullable()->after('failed_at');
    $table->unsignedInteger('retry_count')->default(0)->after('failure_reason');
});
```

**Migration down():** Drop the three columns in reverse order.

### Enum Changes
**None.** `PaymentStatus` already has `Paid` and `Failed`. `ShiftStatus` already has `Paid`. `PaymentAuditAction` already has `Attempt`, `Succeed`, `Fail`, `Retry`, `Release`, `Create`.

### Indexes
No new indexes. The existing `status` index on `payments` is sufficient for dashboard queries scoped by shift.

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| `payments.amount` | payments | DECIMAL(12,2) | **Read-only in this phase** — no math performed |
| `payments.revenue` | payments | DECIMAL(12,2) | **Read-only in this phase** — no math performed |
| `shift_bikers.biker_rate` | shift_bikers | DECIMAL(12,2) | Not touched |
| `shift_bikers.base_fee` | shift_bikers | DECIMAL(12,2) | Not touched |
| `shifts.restaurant_rate` | shifts | DECIMAL(12,2) | Not touched |

**No BCMath calculations in this phase.** All monetary values are read-only — settlement only mutates status fields and timestamps.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Migration | `database/migrations/2026_05_17_000001_add_failure_columns_to_payments_table.php` | Adds `failed_at`, `failure_reason`, `retry_count` columns to `payments` |
| Service | `app/Services/PaymentSettlementService.php` | Encapsulates `markPaid`, `markFailed`, `retry`, `reconcileShiftStatus` |
| Request | `app/Http/Requests/MarkPaidRequest.php` | Authorizes Admin; no body fields |
| Request | `app/Http/Requests/MarkFailedRequest.php` | Authorizes Admin; validates `failure_reason` (required, string, 3..500) |
| Request | `app/Http/Requests/RetryPaymentRequest.php` | Authorizes Admin; no body fields |
| View | `resources/views/shifts/payment-status.blade.php` | Per-shift settlement dashboard grouped by status (or extension of payment-review.blade.php — Developer decides) |
| Test | `tests/Unit/Services/PaymentSettlementServiceTest.php` | Unit tests for service methods, guards, eligibility re-check, shift reconciliation, idempotency |
| Test | `tests/Feature/Controllers/PaymentSettlementControllerTest.php` | Feature tests for HTTP endpoints, authorization, validation, idempotency |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | Add `paymentStatus` (GET), `markPaid` (POST), `markFailed` (POST), `retryPayment` (POST) methods |
| Route | `routes/web.php` | Add 4 new admin routes inside the existing `auth` + `role:admin` middleware group |
| Policy | `app/Policies/ShiftPolicy.php` | Add `paymentStatus`, `markPaid`, `markFailed`, `retryPayment` methods (Admin-only) |
| Model | `app/Models/Payment.php` | Add `failed_at`, `failure_reason`, `retry_count` to `$fillable`; cast `failed_at` as datetime, `retry_count` as integer |
| Model | `app/Models/Shift.php` | Add `allPaymentsPaid(): bool` helper method |
| View | `resources/views/shifts/payment-review.blade.php` | Add "Ver Status de Pagamentos" link visible when shift is `approved` or `paid`; reuse layout pieces |
| View | `resources/views/shifts/show.blade.php` | Add "Ver Status de Pagamentos" button for `approved` and `paid` shifts; show terminal "Pago" badge for `paid` shifts |
| View | `resources/views/shifts/partials/biker-assignments.blade.php` | When shift is `approved` or `paid`, render per-biker payment status (`processing` / `paid` / `failed` with reason) |

---

## 7. Pseudocode

### PaymentSettlementService — Core Logic

```
CLASS PaymentSettlementService:

    /**
     * Returns dashboard data for the per-shift settlement view.
     * Groups payments by current status.
     */
    METHOD getSettlementData(Shift shift):
        ASSERT shift.status IN [ShiftStatus::Approved, ShiftStatus::Paid]

        shift.load([
            'restaurant',
            'shiftBikers.biker.pixKeys',
            'shiftBikers.payment.releasedByUser',
        ])

        groups = {
            processing: [],
            failed: [],
            paid: [],
        }
        totals = { processing: '0.00', failed: '0.00', paid: '0.00' }

        FOR EACH shiftBiker IN shift.shiftBikers:
            payment = shiftBiker.payment
            IF payment IS NULL: CONTINUE

            item = {
                shiftBiker, biker: shiftBiker.biker, payment,
                isEligibleForRetry: payment.status === PaymentStatus::Failed
                                    AND payment.isEligibleForRetry(),
            }

            MATCH payment.status:
                PaymentStatus::Processing:
                    groups.processing.append(item)
                    totals.processing = bcadd(totals.processing, payment.amount, 2)
                PaymentStatus::Failed:
                    groups.failed.append(item)
                    totals.failed = bcadd(totals.failed, payment.amount, 2)
                PaymentStatus::Paid:
                    groups.paid.append(item)
                    totals.paid = bcadd(totals.paid, payment.amount, 2)

        RETURN { shift, groups, totals,
                 allPaid: shift.allPaymentsPaid() }


    /**
     * Mark a `processing` payment as `paid`.
     * Idempotency: if already `paid`, throw — controller returns 422.
     */
    METHOD markPaid(Payment payment, User admin):
        RETURN DB::transaction(fn() => {
            payment.refresh()  // re-load under transaction for race safety

            IF payment.status !== PaymentStatus::Processing:
                THROW RuntimeException(
                    "Payment not in processing status (current: {payment.status})"
                )

            payment.status = PaymentStatus::Paid
            payment.paid_at = now()
            payment.save()

            PaymentAuditLog::create([
                payment_id: payment.id,
                action: PaymentAuditAction::Succeed,
                transaction_ref: "succeed-{payment.id}-" . Str::uuid(),
                payload: {
                    marked_by: admin.id,
                    amount: payment.amount,
                    retry_count: payment.retry_count,
                    paid_at: payment.paid_at,
                },
            ])

            this.reconcileShiftStatus(payment.shiftBiker.shift)

            RETURN payment
        })


    /**
     * Mark a `processing` payment as `failed` with a free-form reason.
     * BR-04: failing this payment must NEVER regress the shift status.
     */
    METHOD markFailed(Payment payment, User admin, string reason):
        RETURN DB::transaction(fn() => {
            payment.refresh()

            IF payment.status !== PaymentStatus::Processing:
                THROW RuntimeException(
                    "Payment not in processing status (current: {payment.status})"
                )

            payment.status = PaymentStatus::Failed
            payment.failed_at = now()
            payment.failure_reason = reason
            payment.save()

            PaymentAuditLog::create([
                payment_id: payment.id,
                action: PaymentAuditAction::Fail,
                transaction_ref: "fail-{payment.id}-" . Str::uuid(),
                error_message: reason,
                payload: {
                    marked_by: admin.id,
                    amount: payment.amount,
                    retry_count: payment.retry_count,
                    failed_at: payment.failed_at,
                },
            ])

            // BR-04: DO NOT touch shift.status here.
            // A failed payment leaves the shift at `approved`.

            RETURN payment
        })


    /**
     * Retry a `failed` payment.
     * BR-06: increments retry_count, writes audit log, transitions back to `processing`.
     * BR-02 + ADR-005 D4: re-checks eligibility — re-blocks if biker no longer qualifies.
     */
    METHOD retry(Payment payment, User admin):
        RETURN DB::transaction(fn() => {
            payment.refresh()

            IF payment.status !== PaymentStatus::Failed:
                THROW RuntimeException(
                    "Only failed payments can be retried (current: {payment.status})"
                )

            // OQ-3C-01: Hard retry cap — refuse if already retried 3 times.
            IF payment.retry_count >= 3:
                THROW RuntimeException(
                    "Payment has reached the maximum retry count (3). Admin intervention required — consider manual bank transfer or contact the biker."
                )

            // Re-evaluate eligibility — state may have drifted since the original release.
            IF NOT payment.isEligibleForRetry():
                biker = payment.shiftBiker.biker
                reasons = []
                IF NOT biker.hasVerifiedPixKey():
                    reasons.append("Sem chave PIX verificada")
                IF NOT biker.hasUserAccount():
                    reasons.append("Sem conta de usuário vinculada")
                THROW RuntimeException(
                    "Payment no longer eligible: " . implode("; ", reasons)
                )

            payment.status = PaymentStatus::Processing
            payment.retry_count = payment.retry_count + 1
            payment.failed_at = null
            payment.failure_reason = null
            payment.save()

            PaymentAuditLog::create([
                payment_id: payment.id,
                action: PaymentAuditAction::Retry,
                transaction_ref: "retry-{payment.id}-" . Str::uuid(),
                payload: {
                    retried_by: admin.id,
                    new_retry_count: payment.retry_count,
                    amount: payment.amount,
                    retry_cap_reached: payment.retry_count >= 3,
                },
            ])

            // OQ-3C-01: If this was the 3rd retry (retry_count now == 3),
            // immediately mark as permanently failed so Admin sees it as terminal.
            IF payment.retry_count >= 3:
                payment.status = PaymentStatus::Failed
                payment.failed_at = now()
                payment.failure_reason = "Limite de retentativas atingido (3/3). Intervenção manual necessária."
                payment.save()

                PaymentAuditLog::create([
                    payment_id: payment.id,
                    action: PaymentAuditAction::Fail,
                    transaction_ref: "auto-fail-cap-{payment.id}-" . Str::uuid(),
                    payload: {
                        reason: "retry_cap_exceeded",
                        retry_count: payment.retry_count,
                    },
                ])

            RETURN payment
        })


    /**
     * Auto-transition shift `approved → paid` ONLY when every payment is `paid`.
     * BR-04 reinforced: shift NEVER moves backward — if even one payment is
     * `processing` or `failed`, we leave the shift at `approved`.
     */
    METHOD reconcileShiftStatus(Shift shift):
        shift.refresh()
        shift.load('shiftBikers.payment')

        IF shift.status !== ShiftStatus::Approved:
            RETURN  // already terminal or not yet approved — nothing to do

        IF shift.allPaymentsPaid():
            shift.status = ShiftStatus::Paid
            shift.save()
```

### Payment Model — Additions

```
// $fillable additions: 'failed_at', 'failure_reason', 'retry_count'
// $casts additions:    'failed_at' => 'datetime', 'retry_count' => 'integer'

METHOD isEligibleForRetry(): bool
    IF this.status !== PaymentStatus::Failed:
        RETURN false

    biker = this.shiftBiker.biker
    IF NOT biker.hasVerifiedPixKey(): RETURN false
    IF NOT biker.hasUserAccount():    RETURN false
    RETURN true
```

### Shift Model — Helper

```
METHOD allPaymentsPaid(): bool
    this.load('shiftBikers.payment')

    // Empty shifts: vacuous truth — no payments to settle.
    // Mirror Phase 3B's allPaymentsReleased() behavior.
    IF this.shiftBikers.isEmpty():
        RETURN true

    RETURN this.shiftBikers.every(
        fn(sb) => sb.payment
                  && sb.payment.status === PaymentStatus::Paid
    )
```

### ShiftController — New Methods

```
METHOD paymentStatus(Request request, Shift shift):
    this.authorize('paymentStatus', shift)

    IF shift.status NOT IN [ShiftStatus::Approved, ShiftStatus::Paid]:
        RETURN redirect()->route('shifts.show', shift)
            .with('error', 'Somente turnos aprovados ou pagos podem ter status revisado.')

    data = app(PaymentSettlementService).getSettlementData(shift)
    RETURN view('shifts.payment-status', data)


METHOD markPaid(MarkPaidRequest request, Shift shift, Payment payment):
    this.assertPaymentBelongsToShift(payment, shift)
    TRY:
        app(PaymentSettlementService).markPaid($payment, request.user())
        RETURN back()->with('success', 'Pagamento marcado como pago.')
    CATCH RuntimeException AS e:
        // Idempotency: invalid state → 422
        RETURN back()->withErrors(['payment' => e.getMessage()], 'payment')
            ->setStatusCode(422)


METHOD markFailed(MarkFailedRequest request, Shift shift, Payment payment):
    this.assertPaymentBelongsToShift(payment, shift)
    TRY:
        app(PaymentSettlementService).markFailed(
            $payment, request.user(), request.validated('failure_reason')
        )
        RETURN back()->with('success', 'Pagamento marcado como falha.')
    CATCH RuntimeException AS e:
        RETURN back()->withErrors(['payment' => e.getMessage()], 'payment')
            ->setStatusCode(422)


METHOD retryPayment(RetryPaymentRequest request, Shift shift, Payment payment):
    this.assertPaymentBelongsToShift(payment, shift)
    TRY:
        app(PaymentSettlementService).retry($payment, request.user())
        RETURN back()->with('success', 'Pagamento reenviado para processamento.')
    CATCH RuntimeException AS e:
        RETURN back()->withErrors(['payment' => e.getMessage()], 'payment')
            ->setStatusCode(422)


PRIVATE METHOD assertPaymentBelongsToShift(Payment payment, Shift shift):
    IF payment.shiftBiker.shift_id !== shift.id:
        ABORT(404)
```

### Form Requests

```
MarkPaidRequest:
    authorize(): this.user().can('markPaid', this.route('shift'))
    rules():     []

MarkFailedRequest:
    authorize(): this.user().can('markFailed', this.route('shift'))
    rules():     [ 'failure_reason' => ['required', 'string', 'min:3', 'max:500'] ]

RetryPaymentRequest:
    authorize(): this.user().can('retryPayment', this.route('shift'))
    rules():     []
```

### ShiftPolicy — New Methods

```
METHOD paymentStatus(User user, Shift shift): RETURN user.isAdmin()
METHOD markPaid(User user, Shift shift):      RETURN user.isAdmin()
METHOD markFailed(User user, Shift shift):    RETURN user.isAdmin()
METHOD retryPayment(User user, Shift shift):  RETURN user.isAdmin()
```

### State Transitions

```
Payment lifecycle (Phase 3C additions in bold):

    pending ──(Phase 3B release)──▶ processing
                                          │
                       ┌──────────────────┼──────────────────┐
                       │                  │                  │
                  **markPaid**       **markFailed**          │
                       │                  │                  │
                       ▼                  ▼                  │
                     paid              failed ──**retry**────┘
                  (TERMINAL)              │   (status → processing,
                                          │    retry_count++)
                                          │
                                  failure_reason set
                                  failed_at set

Shift lifecycle (Phase 3C addition in bold):

    approved ──(allPaymentsPaid())──▶ **paid** (TERMINAL)
        │
        │  If any payment still processing or failed
        └── stays approved (BR-04: never regresses)
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware |
|--------|-----|-------------------|------|------------|
| GET | `shifts/{shift}/payments/status` | `ShiftController@paymentStatus` | Admin | `auth`, `role:admin` |
| POST | `shifts/{shift}/payments/{payment}/mark-paid` | `ShiftController@markPaid` | Admin | `auth`, `role:admin` |
| POST | `shifts/{shift}/payments/{payment}/mark-failed` | `ShiftController@markFailed` | Admin | `auth`, `role:admin` |
| POST | `shifts/{shift}/payments/{payment}/retry` | `ShiftController@retryPayment` | Admin | `auth`, `role:admin` |

> All four routes live in the existing `Route::middleware(['auth', 'role:admin'])` group in `routes/web.php`, alongside Phase 3B routes.

---

## 8. Edge Cases

1. **Mark Paid on a `pending` payment** — Refused at service layer (status guard). Controller returns 422.
2. **Mark Paid on a `paid` payment** — Idempotent refusal. 422, no duplicate audit row.
3. **Mark Paid on a `failed` payment** — Refused (must retry first to bring it back to `processing`). 422.
4. **Mark Failed on a `paid` payment** — Refused. `paid` is terminal. 422.
5. **Mark Failed without `failure_reason`** — `MarkFailedRequest` validation rejects with 422 before reaching the service.
6. **Mark Failed with whitespace-only reason** — Laravel `string` + `min:3` rule rejects. 422.
7. **Retry a `processing` payment** — Refused. Only `failed` is retryable. 422.
8. **Retry a `paid` payment** — Refused. 422.
9. **Retry when biker's PIX key was revoked after the original failure** — Service throws RuntimeException with "Sem chave PIX verificada". Payment stays `failed`. 422.
10. **Retry when biker's User account was deleted** — Service throws with "Sem conta de usuário vinculada". Payment stays `failed`. 422.
11. **Single payment fails, others succeed** — Shift stays `approved` (BR-04). Admin can retry the one failure or mark-failed-then-resolve-manually outside the system (out of scope).
12. **All payments paid simultaneously by concurrent admin clicks** — `reconcileShiftStatus` is called inside each `markPaid` transaction with `shift.refresh()` + `allPaymentsPaid()` check. The last successful `markPaid` transaction is the one that flips the shift to `paid`. Race-safe because the check happens after the payment update inside the same transaction.
13. **Two admins click "Mark Paid" on the same payment** — First transaction wins (status changes to `paid`). Second transaction sees `status = paid` after `refresh()` and throws. 422 returned.
14. **Two admins click "Retry" on the same failed payment** — First transaction wins, status moves to `processing`, `retry_count` becomes N+1. Second transaction sees `status = processing` and throws. 422.
15. **Shift with zero bikers (no payments)** — `allPaymentsPaid()` returns true via vacuous truth. The shift would have already moved past this case in Phase 3B; Phase 3C does not need to handle it explicitly, but the helper is consistent.
16. **Zero-amount payment** — `amount = '0.00'` flows through `markPaid`/`markFailed`/`retry` identically. No special case.
17. **Retry count overflow** — `unsignedInteger` max is 4,294,967,295. Practically unreachable. OQ-3C-01 may impose a lower cap later.
18. **Mark Paid after shift is already `paid`** — Shouldn't happen (all payments are `paid`), but if a stale view triggers it, the status guard refuses. 422.
19. **Audit log unique constraint collision** — `transaction_ref` uses `Str::uuid()` per call, making collisions astronomically improbable. If it ever occurs, the DB unique violation surfaces as a 500; acceptable given probability.
20. **Mark Failed on a payment whose shift is somehow not `approved`** — Shouldn't happen (only `approved` shifts have `processing` payments). Status guard on payment still refuses if it's not `processing`. Service does NOT need to inspect shift status here; payment status is the source of truth.

---

## 9. Acceptance Criteria

### Settlement Dashboard (GET)

- [ ] AC-3C-01: GET `shifts/{shift}/payments/status` returns 200 for Admin on an `approved` shift.
- [ ] AC-3C-02: GET `shifts/{shift}/payments/status` returns 200 for Admin on a `paid` shift (read-only).
- [ ] AC-3C-03: GET `shifts/{shift}/payments/status` returns 403 for non-Admin users.
- [ ] AC-3C-04: GET `shifts/{shift}/payments/status` redirects to `shifts.show` with error if shift is not `approved` or `paid`.
- [ ] AC-3C-05: Dashboard groups payments by status: Processing, Failed, Paid — each in its own section.
- [ ] AC-3C-06: Each `processing` row shows "Marcar como Pago" and "Marcar como Falha" buttons.
- [ ] AC-3C-07: Each `failed` row shows the `failure_reason`, `failed_at`, `retry_count`, and a "Tentar Novamente" button.
- [ ] AC-3C-08: Each `paid` row is read-only and shows `paid_at`.
- [ ] AC-3C-09: Dashboard shows totals per group (processing, failed, paid) using BCMath sums.
- [ ] AC-3C-10: When shift is `paid`, dashboard shows a terminal "Turno pago" banner.

### Mark Paid (POST)

- [ ] AC-3C-11: POST `mark-paid` transitions a `processing` payment to `paid`.
- [ ] AC-3C-12: Mark-paid sets `paid_at` to the current timestamp.
- [ ] AC-3C-13: Mark-paid creates exactly one `PaymentAuditLog` row with `action = 'succeed'` and unique `transaction_ref`.
- [ ] AC-3C-14: Mark-paid on a `pending` payment returns 422 and writes NO audit log.
- [ ] AC-3C-15: Mark-paid on a `paid` payment returns 422 and writes NO duplicate audit log.
- [ ] AC-3C-16: Mark-paid on a `failed` payment returns 422.
- [ ] AC-3C-17: Mark-paid returns 403 for non-Admin users.
- [ ] AC-3C-18: Mark-paid returns 404 when the `payment` does not belong to the `shift` in the URL.

### Mark Failed (POST)

- [ ] AC-3C-19: POST `mark-failed` transitions a `processing` payment to `failed`.
- [ ] AC-3C-20: Mark-failed sets `failed_at` and `failure_reason` from the validated request input.
- [ ] AC-3C-21: Mark-failed creates exactly one `PaymentAuditLog` row with `action = 'fail'`, the reason in `error_message`, and a unique `transaction_ref`.
- [ ] AC-3C-22: Mark-failed validation rejects missing `failure_reason` (422).
- [ ] AC-3C-23: Mark-failed validation rejects `failure_reason` shorter than 3 characters (422).
- [ ] AC-3C-24: Mark-failed validation rejects `failure_reason` longer than 500 characters (422).
- [ ] AC-3C-25: Mark-failed on a non-`processing` payment returns 422 and writes NO audit log.
- [ ] AC-3C-26: **BR-04:** Marking a payment as failed does NOT change the parent shift's status — shift stays `approved`.
- [ ] AC-3C-27: Mark-failed returns 403 for non-Admin users.

### Retry (POST)

- [ ] AC-3C-28: POST `retry` transitions a `failed` payment to `processing`.
- [ ] AC-3C-29: Retry increments `retry_count` by exactly 1.
- [ ] AC-3C-30: Retry clears `failed_at` and `failure_reason` (both back to NULL).
- [ ] AC-3C-31: Retry creates exactly one `PaymentAuditLog` row with `action = 'retry'`, unique `transaction_ref`, and `payload.new_retry_count` matching the new value.
- [ ] AC-3C-32: Retry on a non-`failed` payment returns 422 and writes NO audit log.
- [ ] AC-3C-33: Retry refuses (422) when the biker no longer has a verified PIX key (BR-02 re-check); payment stays `failed`.
- [ ] AC-3C-34: Retry refuses (422) when the biker no longer has a linked User account (ADR-005 D4 re-check); payment stays `failed`.
- [ ] AC-3C-35: Retry returns 403 for non-Admin users.

### Shift Reconciliation

- [ ] AC-3C-36: When the last `processing` payment of a shift is marked `paid`, the shift auto-transitions `approved → paid`.
- [ ] AC-3C-37: Marking a payment paid while siblings are still `processing` leaves the shift at `approved`.
- [ ] AC-3C-38: Marking a payment paid while a sibling is `failed` leaves the shift at `approved` (BR-04 — no regression, no premature promotion).
- [ ] AC-3C-39: A `paid` shift is terminal — `reconcileShiftStatus` never moves a shift backward.

### Financial Integrity

- [ ] AC-3C-40: Payment `amount` and `revenue` are never modified during settlement — only status, timestamps, `failure_reason`, and `retry_count` change.
- [ ] AC-3C-41: All monetary values remain DECIMAL(12,2) with exactly 2 decimal places.

### Audit Trail

- [ ] AC-3C-42: Every successful settlement transition writes exactly one `PaymentAuditLog` row with the correct `action` enum value.
- [ ] AC-3C-43: `transaction_ref` is unique across all audit log rows (UUID-suffixed).
- [ ] AC-3C-44: Refused transitions (422 paths) write NO audit log rows.
- [ ] AC-3C-45: Retry is **refused** when `retry_count >= 3` — RuntimeException is thrown with a message directing Admin to intervene manually ("maximum retry count (3)"). No audit log row is created for the refused retry attempt.
- [ ] AC-3C-46: When the 3rd retry succeeds (retry_count becomes 3), the payment is **immediately auto-failed** with `failure_reason = "Limite de retentativas atingido (3/3). Intervenção manual necessária."`, `failed_at` set, and a `PaymentAuditLog` row with `action = fail` and `payload.reason = "retry_cap_exceeded"`.
- [ ] AC-3C-47: The settlement dashboard displays a prominent warning on payments with `retry_count >= 3` directing the Admin to take manual action (e.g., bank transfer, contact biker).
- [ ] AC-3C-48: A payment with `retry_count >= 3` cannot be retried again — the "Tentar Novamente" button is **hidden** in the UI and the API returns 422.

---

## 10. Test Plan

### Unit: `tests/Unit/Services/PaymentSettlementServiceTest.php`

1. `mark_paid_transitions_processing_to_paid` — Asserts status, `paid_at`, audit log row with `action = succeed`.
2. `mark_paid_refuses_pending_payment` — Asserts RuntimeException, no audit log, status unchanged.
3. `mark_paid_refuses_already_paid_payment` — Idempotency. RuntimeException, no duplicate audit.
4. `mark_paid_refuses_failed_payment` — Must retry first.
5. `mark_failed_transitions_processing_to_failed` — Asserts status, `failed_at`, `failure_reason`, audit log with `action = fail` and `error_message`.
6. `mark_failed_refuses_non_processing_payment` — Status guard.
7. `mark_failed_does_not_change_shift_status` — BR-04. Shift stays `approved`.
8. `retry_transitions_failed_to_processing` — Asserts status, `retry_count + 1`, `failed_at` and `failure_reason` cleared, audit log `action = retry`.
9. `retry_refuses_non_failed_payment` — Status guard.
10. `retry_refuses_when_pix_no_longer_verified` — Mark PIX key `is_verified = false`, attempt retry, assert refusal + payment still `failed`.
11. `retry_refuses_when_user_account_removed` — Delete User row linking the biker, attempt retry, assert refusal.
12. `retry_increments_retry_count_correctly` — Multiple retry cycles (fail → retry → fail → retry) increment counter monotonically.
13. `retry_refuses_when_retry_count_at_cap` — Set retry_count = 3, attempt retry, assert RuntimeException with "maximum retry count" message, payment stays `failed`, no retry audit log row created.
14. `retry_auto_fails_on_third_successful_retry` — Set retry_count = 2, retry succeeds (retry_count becomes 3), then payment is immediately auto-failed with `failure_reason` containing "Limite de retentativas", audit log has `action = fail` with `payload.reason = "retry_cap_exceeded"`.
14. `reconcile_promotes_shift_to_paid_when_all_payments_paid` — Last payment marked paid → shift becomes `paid`.
15. `reconcile_keeps_shift_approved_when_any_payment_processing` — Sibling still processing → shift stays approved.
16. `reconcile_keeps_shift_approved_when_any_payment_failed` — Sibling failed → shift stays approved. **BR-04 cornerstone.**
17. `reconcile_does_not_regress_terminal_paid_shift` — Idempotent on already-paid shifts.
18. `zero_amount_payment_can_be_marked_paid` — Edge case for trip_count = 0 bikers.
19. `getSettlementData_groups_payments_by_status` — Returns correct buckets and BCMath totals.

### Feature: `tests/Feature/Controllers/PaymentSettlementControllerTest.php`

1. `admin_can_view_payment_status_dashboard_for_approved_shift` — 200, view contains all 3 groups.
2. `admin_can_view_payment_status_dashboard_for_paid_shift` — 200, terminal banner visible.
3. `non_admin_cannot_view_payment_status_dashboard` — 403.
4. `payment_status_dashboard_redirects_for_non_approved_shift` — Closed shift → redirect with error.
5. `admin_can_mark_processing_payment_as_paid` — 302 redirect, success flash, DB asserts.
6. `mark_paid_returns_422_for_non_processing_payment` — Idempotency.
7. `mark_paid_returns_404_when_payment_belongs_to_different_shift` — Cross-shift guard.
8. `mark_paid_returns_403_for_non_admin` — Authorization.
9. `admin_can_mark_processing_payment_as_failed` — 302, success flash, DB asserts (status, failed_at, failure_reason).
10. `mark_failed_requires_failure_reason` — 422, validation error on `failure_reason`.
11. `mark_failed_rejects_reason_under_three_chars` — 422.
12. `mark_failed_rejects_reason_over_500_chars` — 422.
13. `mark_failed_does_not_regress_shift_status` — BR-04. Shift stays `approved`.
14. `mark_failed_returns_403_for_non_admin` — Authorization.
15. `admin_can_retry_failed_payment` — 302, success flash, DB asserts (status=processing, retry_count incremented, failed_at null).
16. `retry_returns_422_for_non_failed_payment` — Status guard.
17. `retry_returns_422_when_pix_no_longer_verified` — Re-eligibility check.
18. `retry_returns_422_when_user_account_missing` — Re-eligibility check.
19. `retry_returns_403_for_non_admin` — Authorization.
20. `retry_returns_422_when_retry_count_at_cap` — retry_count = 3, retry refused, payment stays `failed`.
21. `retry_auto_fails_on_third_retry` — retry_count = 2, retry succeeds (→ 3), payment auto-failed with cap reason, audit log `fail` + `retry_cap_exceeded`.
22. `retry_cap_hides_button_in_ui` — Payment with retry_count = 3 does NOT show "Tentar Novamente" button in dashboard view.
20. `marking_last_payment_paid_promotes_shift_to_paid` — End-to-end reconciliation.
21. `marking_payment_paid_with_failed_sibling_keeps_shift_approved` — BR-04 end-to-end.
22. `complete_settlement_cycle_smoke_test` — release → mark paid → mark failed → retry → mark paid → shift becomes paid.

---

## 11. Risks & Mitigations

| # | Risk | Mitigation |
|---|------|-----------|
| R1 | **Concurrent mark-paid race** — Two admins click simultaneously, double audit log, undefined behavior. | Wrap `markPaid` / `markFailed` / `retry` in `DB::transaction()` and call `payment.refresh()` inside the transaction before the status check. First wins; second sees stale state, throws, returns 422. |
| R2 | **Audit `transaction_ref` collision on retry** — A payment retried many times could collide on a deterministic ref. | Use `Str::uuid()` suffix on every `transaction_ref` (`"retry-{id}-{uuid}"`). Collision probability is astronomical. |
| R3 | **Retry count cap enforcement** — After 3 retries, payment is auto-failed and permanently blocked from further retries. | OQ-3C-01 resolved: hard cap at 3. Service refuses retry when `retry_count >= 3`. On the 3rd successful retry, payment is immediately auto-failed with a descriptive reason. UI hides the retry button and shows a warning. Admin must intervene manually. AC-3C-45 through AC-3C-48 cover. |
| R4 | **Partial shift state (some paid, some failed)** — Confusing UX; shift sits at `approved` indefinitely. | Acceptable per BR-04. Dashboard groups make state explicit. UI flags failed payments prominently so the Admin sees outstanding work. Documented in AC-3C-38. |
| R5 | **Shift inadvertently regressing** — A bug in `reconcileShiftStatus` could move a `paid` shift backward. | `reconcileShiftStatus` short-circuits if `shift.status !== ShiftStatus::Approved`. Unit test 17 asserts this. |
| R6 | **Eligibility drift between release and retry** — Biker's PIX revoked after release but before retry. | Service re-evaluates eligibility on every retry via `Payment::isEligibleForRetry()`. AC-3C-33 / AC-3C-34 cover. |
| R7 | **422 status code with Laravel session-flash flow** — Standard `back()->withErrors()` returns 302 by default, not 422. | Controllers explicitly set `setStatusCode(422)` on the error response. Feature tests assert the 422 code, not just the error key. |
| R8 | **View duplication between `payment-review` and `payment-status`** — Developer creates two near-identical Blade files. | Plan permits either extending `payment-review.blade.php` conditionally OR creating a sibling — Developer picks the path with less duplication. Note documented in Section 3 #5. |

---

## 12. Rollout / Verification Steps

1. **Migrate:** `docker exec devcontainer_app_1 php artisan migrate` — runs `2026_05_17_000001_add_failure_columns_to_payments_table`.
2. **Unit tests first:** `docker exec devcontainer_app_1 php artisan test --filter=PaymentSettlementServiceTest` — all 19 tests must pass.
3. **Feature tests:** `docker exec devcontainer_app_1 php artisan test --filter=PaymentSettlementControllerTest` — all 22 tests must pass.
4. **Full suite regression:** `docker exec devcontainer_app_1 php artisan test` — zero regressions vs. Phase 3B baseline.
5. **Manual smoke test (Admin user):**
   1. Create shift, assign 3 bikers, open → close (Phase 3A).
   2. Release all payments (Phase 3B) — shift becomes `approved`, 3 payments `processing`.
   3. Mark payment #1 as **paid** — shift stays `approved`, audit row `succeed` exists.
   4. Mark payment #2 as **failed** with reason "Chave PIX inválida" — shift stays `approved`, payment shows `failure_reason`, audit row `fail` exists.
   5. **Retry** payment #2 — payment back to `processing`, `retry_count = 1`, audit row `retry` exists, `failure_reason` cleared.
   6. Mark payment #2 as **paid** and payment #3 as **paid** — shift auto-transitions to `paid`.
   7. Confirm `shifts.show` displays terminal "Pago" badge and dashboard renders all rows read-only.
6. **Negative smoke:**
   - Attempt to mark a `paid` payment as paid again → expect 422.
   - Attempt to retry a `processing` payment → expect 422.
   - Attempt all 4 endpoints as non-Admin → expect 403.
7. **Rollback path:** `./bin/agent-jail/rollback.sh` to the snapshot taken before this phase if any assertion fails.

---

## Appendix: Files to Create — Detailed Summary

### `app/Services/PaymentSettlementService.php`
- `getSettlementData(Shift): array`
- `markPaid(Payment, User): Payment`
- `markFailed(Payment, User, string): Payment`
- `retry(Payment, User): Payment`
- `reconcileShiftStatus(Shift): void`
- TODO comment for future bulk-retry method (out of scope).

### `resources/views/shifts/payment-status.blade.php`
- Header: "Status de Pagamentos — Turno #ID"
- Three sections: Processing, Failed, Paid — each with table + per-row actions.
- Totals per section using BCMath sums.
- Terminal banner when `shift.status === ShiftStatus::Paid`.

### `tests/Unit/Services/PaymentSettlementServiceTest.php`
19 unit tests covering all service methods, guards, eligibility re-check, reconciliation, and edge cases.

### `tests/Feature/Controllers/PaymentSettlementControllerTest.php`
22 feature tests covering HTTP layer: authorization, validation, idempotency, shift reconciliation, end-to-end smoke.
