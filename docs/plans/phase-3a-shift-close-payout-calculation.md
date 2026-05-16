# Plan: Phase 3A — Shift Close Review & Payout Calculation

**Task ID:** Phase-3A
**Date:** 2026-05-16
**Planner Version:** 1.0
**Complexity:** Complex

---

## 1. Objective

Implement the "calculate but don't pay" phase of the Payout Engine. When an Admin closes an open shift, the system presents a review screen showing all shift_bikers with their trip counts and projected payouts. The Admin confirms no contests, the shift transitions to `closed`, and the system batch-creates `Payment` rows (one per shift_biker) with computed payout amounts in `pending` status. Revenue is also computed and stored per shift_biker. Bikers without linked User accounts or verified PIX keys are flagged with warnings — they are not blocked, only warned.

---

## 2. Source References

### User Stories
- No direct US-XX match — this is an infrastructure phase enabling BR-03 and BR-04 enforcement

### Business Rules
- **BR-02 (PIX Verification):** Payment rows for bikers without verified PIX keys are flagged (warning, not blocked)
- **BR-03 (Manual Release):** Payouts are calculated but not released; Admin must approve in Phase 3B
- **BR-04 (Granular Failure):** Each payment is independent per shift_biker — already enforced by schema

### ADR Decisions
- **ADR-005 D1:** Admin-only close, payout always post-close
- **ADR-005 D2:** Financial rates snapshotted at assignment time (shift_bikers.base_fee, shift_bikers.biker_rate are source of truth)
- **ADR-005 D3:** Inactive bikers blocked from new assignments (already enforced), existing assignments preserved for payout
- **ADR-005 D4:** Bikers must have User accounts to be paid — warn/skip bikers without linked user accounts
- **ADR-005 D5:** Admin must confirm no contested trips before closing

### PRD Sections
- Section 3: Rate & Revenue Management (payout + revenue formulas)
- Section 4: BR-03 (Manual Release), BR-04 (Granular Failure)

### Tech Doc Sections
- Section 3: Business Logic & Formulas (BCMath, payout formula, revenue formula)
- Section 5: Security & Guardrails

---

## 3. Scope

### In Scope
1. **Shift Close Review View** — Admin sees a pre-close review page with all shift_bikers, trip counts, projected payouts, and revenue per biker
2. **Close Confirmation Gate** — Admin must confirm "no contested trips" before the shift transitions to `closed`
3. **Payout Calculation Service** — Batch compute payouts for all shift_bikers on a closed shift using existing `PayoutService` and `RevenueService`
4. **Payment Row Creation** — Create one `Payment` row per shift_biker with computed payout amount, status `pending`
5. **Revenue Column** — Add `revenue DECIMAL(12,2)` column to `payments` table to store company margin per shift_biker
6. **Biker Eligibility Warnings** — Flag bikers without linked User accounts or without verified PIX keys (warning banner, not a block)
7. **ShiftController Refactor** — Split `close` into two-step flow: review (GET) → confirm-close (POST)

### Out of Scope
1. Payment release / Admin approval flow (Phase 3B)
2. PIX API integration — actual payment execution (Phase 4)
3. Payment failure handling and retry logic (Phase 3C)
4. US-01 PDF Trip Sheet (standalone)
5. US-02 Holiday Rate Override (standalone)
6. US-03 Margin Dashboard (Phase 5)
7. US-04 Biker notifications (Phase 5)
8. Contestation workflow — formal trip dispute system (deferred)
9. Removing the auto-close checkbox from ShiftEntryController (ADR-005 D1 cosmetic cleanup — can be done separately)

### Open Questions

_None — all resolved._

- **OQ-1 (Resolved):** Revenue is stored on `payments.revenue`. Confirmed by Product Owner on 2026-05-16.
- **OQ-2 (Resolved):** Closed shifts cannot be re-opened. No backward transition from `closed` to `open`. Keep it simple for MVP. Confirmed by Product Owner on 2026-05-16.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Already enforced in Shift model. No change needed. |
| BR-02 PIX Verification | Yes (partial) | Payout engine flags bikers without verified PIX keys with a warning. Does NOT block Payment creation. Full enforcement when PIX API is integrated in Phase 4. |
| BR-03 Manual Release | Yes | Payouts are created in `pending` status only. No automatic release. Admin approval comes in Phase 3B (shift → `approved` → `paid`). |
| BR-04 Granular Failure | Yes | Already enforced by schema (one Payment per shift_biker, independent status). Payout calculation creates all rows regardless of individual eligibility. |
| BR-05 Last Minute Biker | No | Already enforced. No change needed. |
| BR-06 Payment Retries | No | No retries in this phase. Payment rows are created, not executed. |

---

## 5. Schema Changes

### New Tables

No new tables.

### Modified Tables

```
payments
├── + revenue    DECIMAL(12,2) NULL DEFAULT NULL  — company revenue for this shift_biker
└── timestamps
```

**Rationale:** Revenue is a financial output of the payout calculation and belongs alongside the payment amount. It is computed once at close time and stored for reporting (US-03).

### Indexes

No new indexes — `payments.shift_biker_id` is already indexed.

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| payments.amount | payments | DECIMAL(12,2) | Yes — output of PayoutService::calculate() |
| payments.revenue | payments | DECIMAL(12,2) (NEW) | Yes — output of RevenueService::calculate() |
| shift_bikers.biker_rate | shift_bikers | DECIMAL(12,2) | Yes — source for PayoutService |
| shift_bikers.base_fee | shift_bikers | DECIMAL(12,2) | Yes — source for PayoutService |
| shifts.restaurant_rate | shifts | DECIMAL(12,2) | Yes — source for RevenueService |

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Migration | `database/migrations/YYYY_MM_DD_HHMMSS_add_revenue_to_payments_table.php` | Add `revenue DECIMAL(12,2) NULL` column to `payments` |
| Service | `app/Services/ShiftCloseService.php` | Orchestrates review data preparation, payout batch calculation, Payment creation |
| Request | `app/Http/Requests/ConfirmCloseShiftRequest.php` | Validates `confirmed` boolean + shift status |
| View | `resources/views/shifts/close-review.blade.php` | Close review page: trip summary, payout projections, eligibility warnings, confirm button |
| Test | `tests/Feature/Controllers/ShiftCloseControllerTest.php` | Feature tests for the two-step close flow |
| Test | `tests/Unit/ShiftCloseServiceTest.php` | Unit tests for payout batch calculation + eligibility checks |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | Split `close` into `reviewClose` (GET) + `confirmClose` (POST). Remove direct close logic. |
| Request | `app/Http/Requests/CloseShiftRequest.php` | Update or replace — add `confirmed` boolean field requirement. May be superseded by `ConfirmCloseShiftRequest`. |
| Route | `routes/web.php` | Add GET `shifts/{shift}/close/review` route. Change POST `shifts/{shift}/close` to use `confirmClose`. |
| Model | `app/Models/Payment.php` | Add `revenue` to `$fillable` array and cast to `decimal:2`. |
| View | `resources/views/shifts/show.blade.php` | Change "Encerrar Turno" button to link to the review page instead of direct POST. |
| Policy | `app/Policies/ShiftPolicy.php` | Add `reviewClose` method (same as `close` — Admin only). |

---

## 7. Pseudocode

### ShiftCloseService — Core Orchestration

```
CLASS ShiftCloseService:

    METHOD getReviewData(Shift shift):
        // Eager-load all relationships for the review page
        shift.load([
            'shiftBikers.biker.pixKeys',
            'shiftBikers.biker' → whereHas('user')  // for eligibility check
        ])

        reviewItems = []

        FOR EACH shiftBiker IN shift.shiftBikers:
            biker = shiftBiker.biker
            payout = PayoutService::calculate(
                shiftBiker.base_fee,
                shiftBiker.biker_rate,
                shiftBiker.trips_count
            )
            revenue = RevenueService::calculate(
                shift.restaurant_rate,
                shiftBiker.trips_count,
                payout
            )

            // Eligibility checks (ADR-005 D4)
            hasUser = User::where('biker_id', biker.id).exists()
            hasVerifiedPixKey = biker.pixKeys()
                .where('is_verified', true)
                .exists()

            warnings = []
            IF NOT hasUser:
                warnings.append("Entregador sem conta de usuário vinculada")
            IF NOT hasVerifiedPixKey:
                warnings.append("Entregador sem chave PIX verificada")

            reviewItems.append({
                shiftBiker,
                biker,
                payout,           // string, BCMath result
                revenue,          // string, BCMath result
                hasUser,          // bool
                hasVerifiedPixKey, // bool
                warnings          // array of strings
            })

        RETURN {
            shift,
            reviewItems,
            totalPayout: SUM of all payout values (bcadd),
            totalRevenue: SUM of all revenue values (bcadd),
            hasWarnings: ANY item has warnings
        }


    METHOD closeAndCalculate(Shift shift, User admin):
        // Pre-condition: shift.status MUST be Open
        ASSERT shift.status == ShiftStatus::Open

        // Transition shift to Closed
        shift.status = ShiftStatus::Closed
        shift.closed_at = now()
        shift.save()

        // Calculate and create Payment rows for each shift_biker
        FOR EACH shiftBiker IN shift.shiftBikers:
            payout = PayoutService::calculate(
                shiftBiker.base_fee,
                shiftBiker.biker_rate,
                shiftBiker.trips_count
            )
            revenue = RevenueService::calculate(
                shift.restaurant_rate,
                shiftBiker.trips_count,
                payout
            )

            // Create Payment row — status defaults to 'pending'
            Payment::create([
                shift_biker_id: shiftBiker.id,
                amount: payout,        // DECIMAL(12,2) string
                revenue: revenue,      // DECIMAL(12,2) string
                status: PaymentStatus::Pending
            ])

        RETURN shift  // now closed, with payments created
```

### ShiftController — Two-Step Close Flow

```
CLASS ShiftController:

    // NEW: Step 1 — Show review page (GET)
    METHOD reviewClose(Request request, Shift shift):
        // Authorization: Admin only (via policy)
        this.authorize('reviewClose', shift)

        // Validation: only open shifts can be reviewed for close
        IF shift.status != ShiftStatus::Open:
            RETURN redirect()->route('shifts.show', shift)
                .with('error', 'Somente turnos abertos podem ser encerrados.')

        reviewData = ShiftCloseService.getReviewData(shift)

        RETURN view('shifts.close-review', reviewData)


    // MODIFIED: Step 2 — Confirm and close (POST)
    METHOD confirmClose(ConfirmCloseShiftRequest request, Shift shift):
        // Authorization handled by ConfirmCloseShiftRequest + policy
        // Request validates: shift is open, confirmed == true

        TRY:
            ShiftCloseService.closeAndCalculate(shift, request.user())

            RETURN redirect()->route('shifts.show', shift)
                .with('success', 'Turno encerrado. Pagamentos calculados com sucesso.')
        CATCH RuntimeException:
            RETURN back()->with('error', 'Erro ao encerrar turno.')
```

### ConfirmCloseShiftRequest — Validation

```
CLASS ConfirmCloseShiftRequest:

    METHOD authorize():
        // Policy check: Admin only
        shift = this.route('shift')
        RETURN this.user().can('close', shift)

    METHOD rules():
        RETURN {
            confirmed: ['required', 'accepted']  // checkbox must be checked
        }

    METHOD withValidator(validator):
        validator.after(FUNCTION (validator):
            shift = this.route('shift')
            IF shift.status != ShiftStatus::Open:
                validator.errors().add('status', 'Somente turnos abertos podem ser encerrados.')
        )
```

### State Transitions

```
Shift: open ──(Admin GET /close/review)──▶ Review Page (no state change)
                                              │
                                    Admin checks "confirmed"
                                              │
                        ──(Admin POST /close)──▶ closed
                                              │
                              Payment rows created for each shift_biker
                              (status: pending, amounts calculated)
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware | Description |
|--------|-----|-------------------|------|------------|-------------|
| GET | `shifts/{shift}/close/review` | `ShiftController@reviewClose` | Admin | `auth`, `role:admin` | Show close review page with payout projections |
| POST | `shifts/{shift}/close` | `ShiftController@confirmClose` | Admin | `auth`, `role:admin` | Confirm close + trigger payout calculation |

> **Note:** The existing `POST shifts/{shift}/close` route is retained at the same URI but now maps to `confirmClose` instead of the old `close` method. This maintains backward compatibility — the form action URL doesn't change.

---

## 8. Edge Cases

1. **Shift with zero shift_bikers** — Admin can close a shift with no bikers assigned. No Payment rows are created. Review page shows empty table. Shift still transitions to `closed`.
2. **Shift_biker with 0 trips** — Payout must be exactly `'0.00'`, not NULL. Revenue must also be `'0.00'`. Payment row IS still created with `amount = '0.00'` and `revenue = '0.00'`.
3. **Biker deactivated mid-shift** — Payout engine reads `shift_bikers` only, does NOT filter by `bikers.active`. Deactivated biker still gets a Payment row (ADR-005 D3).
4. **Biker without User account** — Payment row is created (NOT blocked). Warning is displayed on review page. The payment will fail at PIX execution time in Phase 4 if still unlinked.
5. **Biker with no PIX keys at all** — Warning displayed. Payment row created.
6. **Biker with PIX keys but none verified** — Warning displayed. Payment row created.
7. **Concurrent close attempts** — Two admins open the review page simultaneously. First POST wins; second POST gets a validation error because shift is no longer `Open`. No double-creation of Payments.
8. **Shift already closed** — GET review redirects to show page with error. POST gets validation error.
9. **Shift in `draft` status** — Cannot be closed (only `open` → `closed` transition is valid). Validation error.
10. **Negative revenue (loss scenario)** — Valid business case. Revenue stored as negative DECIMAL value. No error.
11. **Payment already exists for shift_biker** — If `confirmClose` is called twice somehow (race condition), the unique constraint on `shift_biker_id` in payments prevents duplicate. Service should check with `firstOrCreate` or catch the unique violation. **OR** the status transition guard (only `open` shifts) prevents this entirely.
12. **Admin navigates away from review page** — No state change. Shift remains `open`. Admin can return to review later.
13. **Very large trip counts** — BCMath handles arbitrary precision. DECIMAL(12,2) max is 999,999,999,999.99 — sufficient for BRL.

---

## 9. Acceptance Criteria

### Close Review View (GET)

- [ ] AC-3A-01: GET `shifts/{shift}/close/review` returns 200 for Admin on an open shift
- [ ] AC-3A-02: GET `shifts/{shift}/close/review` redirects non-Admin users with 403
- [ ] AC-3A-03: GET `shifts/{shift}/close/review` redirects to `shifts.show` with error if shift is not `open`
- [ ] AC-3A-04: Review view displays each shift_biker's name, trip count, biker_rate, base_fee
- [ ] AC-3A-05: Review view displays projected payout per shift_biker (computed via PayoutService)
- [ ] AC-3A-06: Review view displays projected revenue per shift_biker (computed via RevenueService)
- [ ] AC-3A-07: Review view displays total payout across all shift_bikers
- [ ] AC-3A-08: Review view displays total revenue across all shift_bikers
- [ ] AC-3A-09: Review view shows a warning badge next to bikers without a linked User account
- [ ] AC-3A-10: Review view shows a warning badge next to bikers without any verified PIX key
- [ ] AC-3A-11: Review view includes a confirmation checkbox "Confirmo que não há viagens contestadas"
- [ ] AC-3A-12: Review view includes a "Confirmar Encerramento" submit button (disabled until checkbox is checked, via JS)
- [ ] AC-3A-13: Review view shows empty state message when shift has no bikers assigned

### Confirm Close (POST)

- [ ] AC-3A-14: POST `shifts/{shift}/close` with `confirmed=1` transitions shift from `open` to `closed`
- [ ] AC-3A-15: POST `shifts/{shift}/close` sets `closed_at` to current timestamp
- [ ] AC-3A-16: POST `shifts/{shift}/close` without `confirmed` field returns validation error
- [ ] AC-3A-17: POST `shifts/{shift}/close` for a non-open shift returns validation error
- [ ] AC-3A-18: POST `shifts/{shift}/close` redirects non-Admin users with 403
- [ ] AC-3A-19: Successful close redirects to `shifts.show` with success message

### Payment Creation

- [ ] AC-3A-20: On successful close, one Payment row is created per shift_biker
- [ ] AC-3A-21: Each Payment has `amount` equal to PayoutService::calculate() output (BCMath string)
- [ ] AC-3A-22: Each Payment has `revenue` equal to RevenueService::calculate() output (BCMath string)
- [ ] AC-3A-23: Each Payment has `status = 'pending'`
- [ ] AC-3A-24: Payment for shift_biker with 0 trips has `amount = '0.00'` and `revenue = '0.00'`
- [ ] AC-3A-25: Payment for shift_biker with >0 trips follows: amount = base_fee + (biker_rate × trips_count)
- [ ] AC-3A-26: Revenue for shift_biker with >0 trips follows: revenue = (restaurant_rate × trips_count) − amount
- [ ] AC-3A-27: No duplicate Payment rows are created for the same shift_biker (idempotency guard)
- [ ] AC-3A-28: Closing a shift with zero bikers creates zero Payment rows (no error)

### Eligibility Warnings

- [ ] AC-3A-29: Review view flags bikers where `User::where('biker_id', biker.id)` returns no results
- [ ] AC-3A-30: Review view flags bikers where no `pix_keys` with `is_verified = true` exist
- [ ] AC-3A-31: Eligibility warnings do NOT prevent Payment creation — Payment is always created
- [ ] AC-3A-32: Eligibility warnings are purely informational (display-only)

### Payout Formula Correctness (re-verification in integration context)

- [ ] AC-3A-33: Payout for 0 trips = `'0.00'` (BCMath string)
- [ ] AC-3A-34: Payout for 1 trip = base_fee + biker_rate (e.g., base_fee='30.00', biker_rate='15.00' → '45.00')
- [ ] AC-3A-35: Payout for N trips = base_fee + (biker_rate × N) using BCMath scale 2
- [ ] AC-3A-36: Revenue for 0 trips = `'0.00'`
- [ ] AC-3A-37: Revenue can be negative (loss scenario) and is stored correctly as negative DECIMAL

### Data Integrity

- [ ] AC-3A-38: Shift `closed_at` is never NULL after successful close
- [ ] AC-3A-39: All Payment amounts and revenues are stored as DECIMAL(12,2) — exactly 2 decimal places
- [ ] AC-3A-40: Payout reads from `shift_bikers.base_fee` and `shift_bikers.biker_rate` (snapshotted values), NOT from `bikers.rate_per_trip`
- [ ] AC-3A-41: Revenue reads from `shifts.restaurant_rate`, NOT from `restaurants.rate_per_trip`

### Shift Show Page Update

- [ ] AC-3A-42: For open shifts, "Encerrar Turno" button links to GET `shifts.close.review` instead of POST `shifts.close`
- [ ] AC-3A-43: For closed shifts, `shifts.show` displays Payment rows (amount, revenue, status) for each shift_biker
- [ ] AC-3A-44: For closed shifts, biker-assignments partial shows computed payout and revenue values

---

## 10. Security Considerations

- **Authorization:** Both `reviewClose` and `confirmClose` require Admin role. Enforced via `role:admin` middleware + `ShiftPolicy@close` / `ShiftPolicy@reviewClose`.
- **Input Validation:** `ConfirmCloseShiftRequest` requires `confirmed` field to be `'1'` or `true` (`accepted` rule). Shift must be `Open` (validated in `withValidator`).
- **Container Compliance:** All operations occur within `/workspaces/bikerflow`. No external API calls. No file system access outside the project.
- **Financial Safety:**
  - All monetary calculations use BCMath with scale 2 — no floating-point arithmetic.
  - Payment amounts are stored as DECIMAL(12,2) — MySQL enforces precision.
  - No Payment is created in any status other than `pending` — no money moves without Phase 3B Admin approval.
  - Race condition protection: only `open` → `closed` transition is valid. Second concurrent close attempt fails validation.
- **Idempotency:** The Shift status guard (only `open` shifts can be closed) prevents double-creation of Payments. No Payment is created if shift is already `closed`.
- **Data Integrity:** `payments.shift_biker_id` has a unique constraint implied by the one-to-one relationship. The service should use `updateOrCreate` or check existence before creation as a defensive measure.
