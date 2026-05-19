# Plan: Phase 5A — Admin Margin Dashboard

**Task ID:** US-03
**Date:** 2026-05-19
**Planner Version:** 1.0
**Complexity:** Medium

---

## 1. Objective

This plan implements the Admin Margin Dashboard (US-03), enabling the Company Manager to view aggregated financial metrics for a given month: total revenue, total payout, net margin, shift counts, and payment status breakdowns. It consists of a pure aggregation service (`MarginAggregatorService`), a controller with role-based authorization (`MarginDashboardController`), a Blade view with five metric cards in BRL format, and a new route under the existing admin middleware group.

---

## 2. Source References

### User Stories
- **US-03:** As an Admin, I want to see a "Margin Dashboard" showing Revenue vs. Payout for the month.

### Business Rules
- **BR-03 (Payout Formula):** `Payout = 0.00` when `trips_count = 0`; `Payout = base_fee + (biker_rate × trips_count)` when `trips_count > 0`. The PayoutService already implements this verbatim and must be reused.
- **BR-04 (Granular Failure):** Payment statuses (Paid/Pending/Failed/Processing) are tracked per-biker independently. The dashboard counts each status individually and reports unpaid (pending + failed + processing) separately from paid.

### PRD Sections
- Section 3: Rate & Revenue Management (payout and revenue formulas)
- Section 4 — Key Business Rules: BR-03 (BR-04 for payment status granularity)
- Section 5 — User Stories: US-03 (Admin Margin Dashboard)

### Tech Doc Sections
- Section 3: Business Logic & Formulas (Biker Payout Formula, Company Revenue Formula)
- Section 5: Security & Guardrails (role-based access)

---

## 3. Scope

### In Scope
1. Create `app/Services/MarginAggregatorService.php` — pure aggregation layer using PayoutService and RevenueService via constructor injection.
2. Create `app/Http/Controllers/Admin/MarginDashboardController.php` — HTTP layer delegating to the aggregator, protected by `role:admin` middleware.
3. Create `resources/views/admin/margin-dashboard.blade.php` — Blade view with five Tailwind-styled metric cards displaying BRL-formatted values.
4. Add route `GET /admin/margin-dashboard` inside the existing `role:admin` middleware group in `routes/web.php`.
5. All 24 tests must pass (12 unit + 12 feature).

### Out of Scope
- Database migrations — no schema changes.
- Payment API integration (PIX/FitBank/Stark Bank) — future work.
- Biker-facing dashboards (US-04 — separate phase).
- Admin override rates for holiday shifts (US-02 — separate phase).
- Pagination, date-range selectors, or interactivity on the dashboard.

### Open Questions
- None — the test files define exact expected behavior for all scenarios.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Dashboard reads closed shifts only; no state mutation. |
| BR-02 PIX Verification | No | Dashboard does not create or verify PIX keys. |
| BR-03 Manual Release | Yes | Payout calculations must use PayoutService verbatim (shift_biker data, not Payment.amount). Zero trips → zero payout. |
| BR-04 Granular Failure | Yes | Payment status counts must track Paid/Pending/Failed/Processing independently per shift_biker. |
| BR-05 Last Minute Biker | No | Dashboard is read-only; no biker assignment logic. |
| BR-06 Payment Retries | No | No retry logic on the dashboard. |

---

## 5. Schema Changes

### New Tables
No new tables.

### Modified Tables
No modifications.

### Indexes
No new indexes.

### Financial Column Checklist
N/A — no schema changes. All financial values sourced from existing shift_bikers columns (`biker_rate`, `base_fee`, `trips_count`) and shifts column (`restaurant_rate`). All are cast as `'decimal:2'`. BCMath is used for all in-memory aggregation arithmetic at scale 2.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Service | `app/Services/MarginAggregatorService.php` | Aggregates closed shifts for a given year/month, calls PayoutService and RevenueService per shift_biker, returns totals and payment status breakdown. |
| Controller | `app/Http/Controllers/Admin/MarginDashboardController.php` | Injects MarginAggregatorService; calls `aggregate()` with current year/month; passes data to view. |
| View | `resources/views/admin/margin-dashboard.blade.php` | Renders five metric cards with Tailwind styling and BRL formatting. |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Route | `routes/web.php` | Add `Route::get('/admin/margin-dashboard', [MarginDashboardController::class, 'index'])->name('margin-dashboard');` inside the existing `Route::middleware(['auth', 'role:admin'])->group()` block (after line 59 region). |

---

## 7. Pseudocode

### MarginAggregatorService::aggregate($year, $month)

```
CONSTRUCTOR injects PayoutService, RevenueService

FUNCTION aggregate(year, month):
    // 1. Query closed shifts in the target year/month
    shifts = Shift
        WHERE status == ShiftStatus::Closed
        AND YEAR(closed_at) == year
        AND MONTH(closed_at) == month
        WITH ('shiftBikers.payment')
        get all

    // 2. Initialize accumulators (all string, scale 2)
    total_revenue  = "0.00"
    total_payout   = "0.00"
    shift_count    = 0
    paid_count     = 0
    unpaid_count   = 0
    payment_detail = { paid: 0, pending: 0, failed: 0, processing: 0 }

    // 3. Iterate each shift
    FOR EACH shift IN shifts:
        shift_count = shift_count + 1
        restaurant_rate = shift.restaurant_rate  // already decimal:2 cast

        // 4. Iterate each shift_biker
        FOR EACH shift_biker IN shift.shiftBikers:
            // 4a. Calculate payout via PayoutService
            payout = PayoutService->calculate(
                         base_fee    = shift_biker.base_fee,
                         biker_rate  = shift_biker.biker_rate,
                         trips_count = shift_biker.trips_count
                     )

            // 4b. Calculate revenue via RevenueService
            revenue = RevenueService->calculate(
                          restaurant_rate = restaurant_rate,
                          trips_count     = shift_biker.trips_count,
                          payout          = payout
                      )

            // 4c. Accumulate with BCMath
            total_payout  = bcadd(total_payout,  payout,  2)
            total_revenue = bcadd(total_revenue, revenue, 2)

            // 4d. Count payment statuses (if payment exists)
            IF shift_biker.payment IS NOT NULL:
                status = shift_biker.payment.status
                CASE status:
                    PaymentStatus::Paid:
                        paid_count = paid_count + 1
                        payment_detail.paid = payment_detail.paid + 1
                    PaymentStatus::Pending:
                        unpaid_count = unpaid_count + 1
                        payment_detail.pending = payment_detail.pending + 1
                    PaymentStatus::Failed:
                        unpaid_count = unpaid_count + 1
                        payment_detail.failed = payment_detail.failed + 1
                    PaymentStatus::Processing:
                        unpaid_count = unpaid_count + 1
                        payment_detail.processing = payment_detail.processing + 1
                END
            END
        END
    END

    // 5. Net margin: revenue - payout (can be negative)
    net_margin = bcsub(total_revenue, total_payout, 2)

    // 6. Return result
    RETURN {
        total_revenue:  total_revenue,   // string, e.g. "-42.50"
        total_payout:   total_payout,    // string, e.g. "207.50"
        net_margin:     net_margin,      // string, e.g. "-250.00"
        shift_count:    shift_count,     // integer
        paid_count:     paid_count,      // integer
        unpaid_count:   unpaid_count,    // integer
        payment_detail: payment_detail   // array of integers keyed by status
    }
END
```

### MarginDashboardController::index()

```
CONSTRUCTOR injects MarginAggregatorService

FUNCTION index():
    aggregator = MarginAggregatorService
    data = aggregator->aggregate(
        year  = now()->year,
        month = now()->month
    )
    FORMAT data for view:
        brl_currency = function(val):
            RETURN "R$ " + number_format(val, 2, ',', '.')
        data.brl_total_revenue  = brl_currency(data.total_revenue)
        data.brl_total_payout   = brl_currency(data.total_payout)
        data.brl_net_margin     = brl_currency(data.net_margin)
    RETURN view('admin.margin-dashboard', data)
END
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware |
|--------|-----|-------------------|------|------------|
| GET | `/admin/margin-dashboard` | `MarginDashboardController@index` | Admin | `auth`, `role:admin` |

---

## 8. Edge Cases

1. **Empty month** — No closed shifts in target month → all financial strings `"0.00"`, all counts `0`. No shifts returned from query; loop body never executes.
2. **Zero trips (BR-03)** — `trips_count = 0` → PayoutService returns `"0.00"`; RevenueService returns `"0.00"`. The shift is still counted.
3. **Shift without Payment rows** — The shift is still counted in `shift_count` and contributes to financial aggregation via shift_biker data. Payment status loops are skipped for shift_bikers without a payment.
4. **Negative margin** — Restaurant rate < effective biker rate → `bcsub()` produces a negative string starting with `"-"` (e.g., `"-95.00"`). View must render this correctly with minus sign.
5. **Multi-shift, multi-biker BCMath precision** — Accumulating 30+ rows with `bcadd(..., 2)` must match manual BCMath sums exactly. No floating-point drift.
6. **Shifts from other months excluded** — Query uses `whereYear('closed_at', $year)->whereMonth('closed_at', $month)`. Only closed shifts in the exact target month are aggregated.
7. **Draft and Open shifts excluded** — Only `ShiftStatus::Closed` is queried. Draft and Open shifts produce `0.00` financials.
8. **Payment status enumeration completeness** — The `payment_detail` sub-array must account for all four PaymentStatus enum values: Paid, Pending, Failed, Processing. `unpaid_count` = Pending + Failed + Processing.
9. **BRL formatting of large values** — `number_format($value, 2, ',', '.')` must produce `R$ 11.250,00` for `11250.00` (dot thousands, comma decimal, `R$ ` prefix).
10. **BRL formatting of negative values** — `number_format("-95.00", 2, ',', '.')` produces `"-95,00"`. The BRL prefix is added as `"R$ -95,00"`. The minus sign must be visible in the rendered view.

---

## 9. Acceptance Criteria

### Unit Tests (MarginAggregatorService — 12 tests)

- [ ] **AC-04:** When no closed shifts exist for the target month, `aggregate()` returns `"0.00"` for `total_revenue`, `total_payout`, `net_margin`; `0` for `shift_count`, `paid_count`, `unpaid_count`.
- [ ] **AC-05:** Single shift, single biker, trips=5, base_fee=25.00, biker_rate=10.00 → `total_payout` = `"75.00"` (25.00 + 10.00×5).
- [ ] **AC-06:** Same scenario as AC-05, restaurant_rate=15.00 → `total_revenue` = `"0.00"` ((15.00×5) − 75.00).
- [ ] **AC-07:** Same scenario as AC-05 → `net_margin` = `"-75.00"` (0.00 − 75.00).
- [ ] **AC-08:** trips_count = 0 → `total_payout` = `"0.00"`, `total_revenue` = `"0.00"`, `net_margin` = `"0.00"`, `shift_count` = 1.
- [ ] **AC-09:** Three closed shifts in current month → `shift_count` = 3. Shift from previous month → excluded (`shift_count` = 1, not 2).
- [ ] **AC-10:** Three bikers with Pending, Failed, Paid statuses → `paid_count` = 1.
- [ ] **AC-11:** Four bikers with Paid, Pending, Failed, Processing → `unpaid_count` = 3; `payment_detail` = {paid: 1, pending: 1, failed: 1, processing: 1}.
- [ ] **AC-12:** Multi-shift, multi-biker BCMath aggregation → `total_payout` = `"207.50"`, `total_revenue` = `"-42.50"`, `net_margin` = `"-250.00"`, `shift_count` = 2, `paid_count` = 1, `unpaid_count` = 2.
- [ ] **AC-13:** Restaurant rate (5.00) < biker rate (10.00) → `net_margin` starts with `"-"` (e.g., `"-95.00"`). Large-scale (5 shifts): `total_payout` = `"1000.00"`, `total_revenue` = `"-850.00"`, `net_margin` = `"-1850.00"`.
- [ ] **BCMath Precision:** 10 shifts × 3 bikers each (30 rows) with `rate_per_trip=11.11`, `base_fee=12.34`, `biker_rate=7.77` → all three financial strings match manual BCMath sum at scale 2.
- [ ] **Edge Case — No Payments:** Closed shift with shift_bikers but no Payment rows → `shift_count` = 1, `total_payout` = `"75.00"` (derived from shift_biker data, not Payment.amount).

### Feature Tests (MarginDashboardController — 12 tests)

- [ ] **AC-01:** Authenticated admin → HTTP 200 from `GET /admin/margin-dashboard`.
- [ ] **AC-02:** Authenticated restaurant_manager → HTTP 403.
- [ ] **AC-02a:** Authenticated biker → HTTP 403.
- [ ] **AC-03:** Unauthenticated → redirect to `route('login')`.
- [ ] **AC-04:** Empty month view → contains `R$ 0,00`.
- [ ] **AC-14:** View contains five card labels: `"Receita Total"`, `"Pagamentos"`, `"Margem Líquida"`, `"Turnos Fechados"`, `"Pagamentos (Pago/Pendente)"`.
- [ ] **AC-15 (large values):** View shows `R$ ` prefix and `,` decimal separator.
- [ ] **AC-15 (payout):** Single biker payout 75.00 → view shows `R$ 75,00`.
- [ ] **AC-15 (revenue):** Revenue 25.00 → view shows `R$ 25,00`.
- [ ] **Integration:** Closed shift with trips=10, rate=20.00 → view shows `R$ 125,00` (payout), `R$ 75,00` (revenue), `50,00` (margin), `1` (shift count), view name `admin.margin-dashboard`.
- [ ] **Integration — shift count:** 5 closed shifts → view shows `"Turnos Fechados"` label and `5`.
- [ ] **Boundary:** Draft and Open shifts only → view shows `R$ 0,00` (only closed shifts aggregated).

---

## 10. Security Considerations

- **Authorization:** The route is nested inside `Route::middleware(['auth', 'role:admin'])`, ensuring only authenticated Admin users can access the dashboard. Non-admin authenticated users receive HTTP 403; unauthenticated users are redirected to login.
- **Input Validation:** The controller takes no user input — year/month are derived from `now()` server-side. No request validation needed.
- **Container Compliance:** All operations are within `/workspaces/bikerflow`. No external API calls. No host filesystem access.
- **Financial Safety:** All monetary accumulation uses `bcadd`, `bcsub`, `bcmul` at scale 2. The PayoutService and RevenueService (existing, tested) are the single sources of truth for the formulas. The aggregator only calls these services and sums their string outputs — no independent financial logic exists in the aggregator.
- **Read-Only:** The entire feature is read-only. No mutations to shifts, shift_bikers, or payment records.

## Self-Check Validation

- [x] Every referenced BR-XX rule has a corresponding implementation constraint in Section 4.
- [x] The payout formula uses PayoutService verbatim (BR-03): trips=0 → "0.00", trips>0 → base_fee + (biker_rate × trips_count).
- [x] Revenue formula uses RevenueService verbatim: (restaurant_rate × trips_count) − Payout.
- [x] All BCMath calls in pseudocode use scale 2: `bcadd(..., 2)`, `bcsub(..., 2)`, `bcmul(..., 2)`.
- [x] No plan step requires access outside `/workspaces/bikerflow`.
- [x] 24 acceptance criteria are atomic, testable, and unambiguous.
- [x] The plan contains no application code — only pseudocode and architectural decisions.
