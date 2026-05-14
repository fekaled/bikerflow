# Plan: BR-03 — Payout Formula Service & Unit Tests

**Task ID:** BR-03
**Date:** 2026-05-14
**Planner Version:** 1.0
**Complexity:** Simple

---

## 1. Objective

Implement the core payout calculation engine for BikerFlow: a `PayoutService` class with a `calculate()` method that enforces the BR-03 payout formula exactly as stated in the PRD and Technical Documentation. All arithmetic uses BCMath with scale 2 — no floats anywhere in the financial path. A comprehensive unit test suite validates every edge case. A supporting migration creates the `shift_bikers` table whose columns supply the formula's inputs.

---

## 2. Source References

### User Stories
- No direct user story. This is foundational business logic that US-01 through US-04 depend on.

### Business Rules
- **BR-03 Manual Release / Payout Formula** — The core rule being implemented. No automated payments without admin approval. The formula itself: `Payout = 0.00` if `trips_count = 0`, else `base_fee + (biker_rate × trips_count)`.

### PRD Sections
- Section 3: Rate & Revenue Management — defines the biker payout formula and base fee concept
- Section 4: Business Rules — BR-03 table entry

### Tech Doc Sections
- Section 3: Business Logic & Formulas — the piecewise payout formula with exact notation
- Section 5: Security & Guardrails — audit context for BR-03

---

## 3. Scope

### In Scope

1. **PayoutService** — `app/Services/PayoutService.php` with `calculate(string $baseFee, string $bikerRate, int $tripsCount): string`
2. **Unit test** — `tests/Unit/PayoutServiceTest.php` with data provider covering all formula branches and edge cases
3. **Migration for shift_bikers** — The table that stores `base_fee`, `biker_rate`, and `trips_count` — the three inputs to the formula
4. **Supporting migrations** — `bikers` table (owns `base_fee` and `rate_per_trip`) and `shifts` table (required by FK from `shift_bikers`)
5. **RevenueService** — `app/Services/RevenueService.php` with `calculate(string $restaurantRate, int $tripsCount, string $payout): string` — the companion formula from PRD Section 3

### Out of Scope

1. HTTP controllers, routes, middleware, views
2. Payment processing or PIX integration
3. Admin approval workflow (controller-level BR-03 enforcement)
4. Shift status state machine
5. Model factories for all entities (only minimal factories needed for tests)
6. Enums (ShiftStatus, PaymentStatus, etc.)
7. BR-01 workflow locking
8. BR-02 PIX verification
9. BR-04 granular failure handling
10. BR-06 payment retry audit logging
11. Biker notification (US-04)

### Open Questions

1. **Should PayoutService accept negative rates?** The PRD does not explicitly forbid negative rates. The service will accept any valid numeric string and let the caller enforce business constraints. If negative input is passed, BCMath handles it correctly. Flagging for future validation layer.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | No | Not relevant to the payout calculation itself. |
| BR-02 PIX Verification | No | Not relevant to calculation. |
| BR-03 Manual Release | **Yes — primary target** | The payout formula IS the implementation. The "manual release" aspect (admin must approve before payment) is deferred to controller phase. This plan implements the **calculation** part of BR-03. |
| BR-04 Granular Failure | No | Calculation is stateless; failure handling is deferred. |
| BR-05 Last Minute Biker | No | Not relevant to calculation. |
| BR-06 Payment Retries | No | Not relevant to calculation. |

---

## 5. Schema Changes

### New Tables

Only the tables that directly supply inputs to the payout formula are created. Full schema from the phase-1 plan is deferred.

```
bikers
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── name                  VARCHAR(255) NOT NULL
├── phone                 VARCHAR(20) NOT NULL UNIQUE
├── rate_per_trip         DECIMAL(12,2) NOT NULL DEFAULT '0.00'    — biker_rate source
├── base_fee              DECIMAL(12,2) NOT NULL DEFAULT '0.00'    — base_fee source
├── active                TINYINT(1) NOT NULL DEFAULT 1
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL

shifts
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── restaurant_id         BIGINT UNSIGNED FK(restaurants.id) CASCADE DELETE
├── workflow_type         VARCHAR(20) NOT NULL DEFAULT 'live_tick'
├── status                VARCHAR(20) NOT NULL DEFAULT 'open'
├── restaurant_rate       DECIMAL(12,2) NOT NULL DEFAULT '0.00'
├── started_at            TIMESTAMP NOT NULL
├── closed_at             TIMESTAMP NULL
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL

restaurants
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── name                  VARCHAR(255) NOT NULL
├── rate_per_trip         DECIMAL(12,2) NOT NULL DEFAULT '0.00'
├── active                TINYINT(1) NOT NULL DEFAULT 1
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL

shift_bikers
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── shift_id              BIGINT UNSIGNED FK(shifts.id) CASCADE DELETE
├── biker_id              BIGINT UNSIGNED FK(bikers.id) CASCADE DELETE
├── trips_count           UNSIGNED INT NOT NULL DEFAULT 0          — formula input
├── biker_rate            DECIMAL(12,2) NOT NULL                    — snapshotted rate (formula input)
├── base_fee              DECIMAL(12,2) NOT NULL                    — snapshotted fee (formula input)
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL
    UNIQUE INDEX: (shift_id, biker_id)
```

> **Rationale:** The `shift_bikers` table is the direct data source for `PayoutService::calculate()`. Its columns `base_fee`, `biker_rate`, and `trips_count` are the exact three parameters the formula requires. Rates are snapshotted from the parent `bikers` table at assignment time to ensure historical accuracy.

### Modified Tables

No modifications to existing tables.

### Indexes

| Index | Table | Columns | Reason |
|-------|-------|---------|--------|
| `shift_bikers_shift_biker_unique` | `shift_bikers` | `(shift_id, biker_id)` | One assignment per biker per shift |

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| rate_per_trip | bikers | DECIMAL(12,2) | Yes |
| base_fee | bikers | DECIMAL(12,2) | Yes |
| restaurant_rate | shifts | DECIMAL(12,2) | Yes |
| rate_per_trip | restaurants | DECIMAL(12,2) | Yes |
| biker_rate | shift_bikers | DECIMAL(12,2) | Yes |
| base_fee | shift_bikers | DECIMAL(12,2) | Yes |

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Migration | `database/migrations/<ts>_create_restaurants_table.php` | Restaurants table (FK parent for shifts) |
| Migration | `database/migrations/<ts>_create_bikers_table.php` | Bikers table — source of rate_per_trip and base_fee |
| Migration | `database/migrations/<ts>_create_shifts_table.php` | Shifts table (FK parent for shift_bikers) |
| Migration | `database/migrations/<ts>_create_shift_bikers_table.php` | Shift-Biker pivot — stores formula inputs |
| Service | `app/Services/PayoutService.php` | BCMath payout calculation (BR-03) |
| Service | `app/Services/RevenueService.php` | BCMath revenue calculation |
| Model | `app/Models/Restaurant.php` | Eloquent model for restaurants |
| Model | `app/Models/Biker.php` | Eloquent model for bikers |
| Model | `app/Models/Shift.php` | Eloquent model for shifts |
| Model | `app/Models/ShiftBiker.php` | Eloquent pivot model — formula input source |
| Test | `tests/Unit/PayoutServiceTest.php` | Unit tests for payout formula |
| Test | `tests/Unit/RevenueServiceTest.php` | Unit tests for revenue formula |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| N/A | N/A | No existing files modified — greenfield |

---

## 7. Pseudocode

### PayoutService::calculate

```
CLASS PayoutService

    /**
     * Calculate biker payout per BR-03 formula.
     *
     * @param  string  $baseFee    — snapshotted base_fee from shift_bikers
     * @param  string  $bikerRate  — snapshotted biker_rate from shift_bikers
     * @param  int     $tripsCount — current trips_count from shift_bikers
     * @return string  — payout amount, exactly 2 decimal places
     * @throws InvalidArgumentException if tripsCount < 0
     */
    PUBLIC FUNCTION calculate(string $baseFee, string $bikerRate, int $tripsCount): string

        // GUARD: negative trips are invalid
        IF tripsCount < 0:
            THROW InvalidArgumentException("tripsCount must be >= 0, got: {tripsCount}")

        // BR-03: zero trips → zero payout (base fee is NOT paid)
        IF tripsCount === 0:
            RETURN '0.00'

        // BR-03: Payout = base_fee + (biker_rate × trips_count)
        tripTotal = bcmul(bikerRate, (string) tripsCount, 2)     // scale 2
        payout    = bcadd(baseFee, tripTotal, 2)                  // scale 2

        RETURN payout    // STRING with exactly 2 decimal places

    END FUNCTION

END CLASS
```

**PRD reference:** Section 3 — "Biker Payout = Base Fee + (Biker Rate × Trips)"
**Tech Doc reference:** Section 3 — Piecewise formula with trips_count = 0 guard

### RevenueService::calculate

```
CLASS RevenueService

    /**
     * Calculate company revenue.
     *
     * @param  string  $restaurantRate — snapshotted rate from shifts.restaurant_rate
     * @param  int     $tripsCount     — trips from shift_bikers
     * @param  string  $payout         — output of PayoutService::calculate()
     * @return string  — revenue amount, exactly 2 decimal places (can be negative)
     */
    PUBLIC FUNCTION calculate(string $restaurantRate, int $tripsCount, string $payout): string

        // Zero trips → zero revenue
        IF tripsCount === 0:
            RETURN '0.00'

        // Revenue = (restaurant_rate × trips_count) - Payout
        gross   = bcmul(restaurantRate, (string) tripsCount, 2)  // scale 2
        revenue = bcsub(gross, payout, 2)                        // scale 2

        RETURN revenue    // Can be negative — that's a valid loss scenario

    END FUNCTION

END CLASS
```

**PRD reference:** Section 3 — "Company Revenue = (Restaurant Rate × Trips) - Biker Payout"

### Data Flow Diagram

```
┌──────────┐     snapshot      ┌──────────────┐
│  bikers  │ ──────────────▶  │ shift_bikers  │
│ base_fee │                   │ base_fee      │──┐
│ rate     │                   │ biker_rate    │  │
└──────────┘                   │ trips_count   │  │  read inputs
                               └──────────────┘  │
                                                  ▼
                                         ┌─────────────────┐
                                         │ PayoutService   │
                                         │ ::calculate()   │
                                         │                 │
                                         │ if trips=0 → 0  │
                                         │ else: fee+rate×n│
                                         └────────┬────────┘
                                                  │
                                                  ▼ payout (string)
                                         ┌─────────────────┐
                               ┌─────▶   │ RevenueService  │
                               │         │ ::calculate()   │
                               │         └─────────────────┘
                               │
                    ┌──────────────┐
                    │    shifts    │
                    │ rest_rate    │──┘ read restaurant_rate
                    └──────────────┘
```

### Route Design

N/A — No HTTP routes. PayoutService and RevenueService are pure PHP classes invoked programmatically.

---

## 8. Edge Cases

1. **Zero trips (BR-03 critical):** `calculate('25.00', '10.00', 0)` must return `'0.00'` — the string, not `0`, `0.0`, `NULL`, or `'0'`. The base fee is NOT paid when trips = 0.

2. **Zero base fee:** `calculate('0.00', '10.00', 5)` must return `'50.00'` — no crash, no NaN, just pure rate × trips.

3. **Zero biker rate:** `calculate('25.00', '0.00', 5)` must return `'25.00'` — only the base fee.

4. **All zeroes, zero trips:** `calculate('0.00', '0.00', 0)` returns `'0.00'`.

5. **All zeroes, positive trips:** `calculate('0.00', '0.00', 5)` returns `'0.00'`.

6. **Single trip (minimum non-zero):** `calculate('25.00', '10.00', 1)` returns `'35.00'`.

7. **Decimal biker rate:** `calculate('25.00', '12.50', 7)` returns `'112.50'` — tests fractional rate precision.

8. **Large trip count:** `calculate('25.00', '10.00', 100)` returns `'1025.00'` — validates no overflow with BCMath.

9. **Large numbers (DECIMAL boundary):** `calculate('999999.99', '99999.99', 999)` must produce an exact result with no precision loss. DECIMAL(12,2) max is `99999999999.99`.

10. **Negative trips_count:** Must throw `InvalidArgumentException`. Enforced by the service guard clause.

11. **Revenue is negative (loss):** `RevenueService::calculate('10.00', 5, '75.00')` returns `'-25.00'` — valid business scenario, not an error.

12. **Revenue is exactly zero (break-even):** `RevenueService::calculate('15.00', 5, '75.00')` returns `'0.00'`.

13. **BCMath scale consistency:** Every operation uses scale 2. The return value must always have exactly 2 decimal places.

---

## 9. Acceptance Criteria

### Migration

- [ ] AC-01: `php artisan migrate` completes without errors, creating `restaurants`, `bikers`, `shifts`, and `shift_bikers` tables.
- [ ] AC-02: `bikers` table has `rate_per_trip` and `base_fee` as `DECIMAL(12,2)` with default `'0.00'`.
- [ ] AC-03: `shift_bikers` table has `trips_count` as `UNSIGNED INT` default 0, `biker_rate` and `base_fee` as `DECIMAL(12,2)`.
- [ ] AC-04: `shift_bikers` has a unique index on `(shift_id, biker_id)`.

### PayoutService (BR-03 Formula)

- [ ] AC-05: `PayoutService::calculate('25.00', '10.00', 0)` returns `'0.00'` — zero trips guard.
- [ ] AC-06: `PayoutService::calculate('25.00', '10.00', 1)` returns `'35.00'` — minimum non-zero case.
- [ ] AC-07: `PayoutService::calculate('25.00', '10.00', 5)` returns `'75.00'` — standard multi-trip.
- [ ] AC-08: `PayoutService::calculate('25.00', '10.00', 100)` returns `'1025.00'` — large count.
- [ ] AC-09: `PayoutService::calculate('0.00', '10.00', 3)` returns `'30.00'` — zero base fee.
- [ ] AC-10: `PayoutService::calculate('25.00', '0.00', 3)` returns `'25.00'` — zero biker rate.
- [ ] AC-11: `PayoutService::calculate('25.00', '12.50', 7)` returns `'112.50'` — decimal rate precision.
- [ ] AC-12: `PayoutService::calculate('0.00', '0.00', 0)` returns `'0.00'` — all zeroes.
- [ ] AC-13: `PayoutService::calculate('0.00', '0.00', 5)` returns `'0.00'` — all zeroes, positive trips.
- [ ] AC-14: `PayoutService::calculate('25.00', '10.00', -1)` throws `InvalidArgumentException` — negative trips rejected.
- [ ] AC-15: All `PayoutService::calculate()` return values are PHP `string` type, not `float`.
- [ ] AC-16: All return values match regex `/^-?\d+\.\d{2}$/` — exactly 2 decimal places.

### RevenueService

- [ ] AC-17: `RevenueService::calculate('15.00', 5, '75.00')` returns `'0.00'` — break-even.
- [ ] AC-18: `RevenueService::calculate('20.00', 5, '75.00')` returns `'25.00'` — profit.
- [ ] AC-19: `RevenueService::calculate('10.00', 5, '75.00')` returns `'-25.00'` — loss (negative valid).
- [ ] AC-20: `RevenueService::calculate('15.00', 0, '0.00')` returns `'0.00'` — zero trips.
- [ ] AC-21: All `RevenueService::calculate()` return values are PHP `string` type.

### Models

- [ ] AC-22: `Biker` model exists with `$fillable` containing `name`, `phone`, `rate_per_trip`, `base_fee`, `active`.
- [ ] AC-23: `Biker` model casts `rate_per_trip` and `base_fee` as `decimal:2`.
- [ ] AC-24: `ShiftBiker` model exists with `$fillable` containing `shift_id`, `biker_id`, `trips_count`, `biker_rate`, `base_fee`.
- [ ] AC-25: `ShiftBiker` model casts `biker_rate` and `base_fee` as `decimal:2`.
- [ ] AC-26: `ShiftBiker` model has `belongsTo(Shift::class)` and `belongsTo(Biker::class)` relationships.

### Tests

- [ ] AC-27: `tests/Unit/PayoutServiceTest.php` passes with `php artisan test --filter=PayoutServiceTest`.
- [ ] AC-28: `tests/Unit/RevenueServiceTest.php` passes with `php artisan test --filter=RevenueServiceTest`.
- [ ] AC-29: Test coverage uses a PHPUnit data provider with at least 10 dataset rows for `PayoutServiceTest`.
- [ ] AC-30: `php artisan test` (full suite) passes with zero failures.

---

## 10. Security Considerations

- **Authorization:** N/A — no HTTP endpoints. Services are plain PHP classes.
- **Input Validation:** `PayoutService::calculate()` validates `tripsCount >= 0` and throws `InvalidArgumentException` on violation. Input strings are passed to BCMath which handles non-numeric gracefully (returns `'0'` with a warning). Caller is responsible for pre-validating numeric strings.
- **Container Compliance:** All code lives within `/workspaces/bikerflow`. No external access, no network calls, no file I/O outside the project.
- **Financial Safety:**
  - All financial columns are `DECIMAL(12,2)` at the database level.
  - All arithmetic uses `bcadd()`, `bcmul()`, `bcsub()` with scale `2`.
  - Zero floats in the financial calculation path.
  - Return type is always `string`, never `float`.
  - The zero-trips guard returns a literal `'0.00'` string — no conditional arithmetic that could introduce float intermediaries.
