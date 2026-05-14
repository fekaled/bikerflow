# Audit Report: BR-03 Payout Formula Service & Unit Tests

**Task ID:** BR-03
**Date:** 2026-05-14
**Auditor:** Validator Agent
**Plan Reference:** `docs/plans/BR-03-payout-formula.md`
**Test Suite Status:** 🔴 RED — 2 failures in `PayoutServiceTest`

---

## Verdict

**🔴 FAIL**

### Findings Summary

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 1 |
| Medium | 0 |
| Low | 0 |

---

## Phase 1: PRD Compliance

### Acceptance Criteria

| AC | Status | Code Location | Notes |
|----|--------|---------------|-------|
| AC-01 | ✅ | `tests/Feature/Payout/PayoutIntegrationTest.php:L50` | `migrate:fresh` runs clean; RefreshDatabase creates all tables |
| AC-02 | ✅ | `database/migrations/2026_05_14_000002_create_bikers_table.php:L10-L11` | `rate_per_trip` and `base_fee` are `DECIMAL(12,2)` default `'0.00'` |
| AC-03 | ✅ | `database/migrations/2026_05_14_000004_create_shift_bikers_table.php:L7-L10` | `trips_count` UNSIGNED INT default 0; `biker_rate` and `base_fee` DECIMAL(12,2) |
| AC-04 | ✅ | `database/migrations/2026_05_14_000004_create_shift_bikers_table.php:L14` | Unique index on `(shift_id, biker_id)` confirmed in DB |
| AC-05 | ✅ | `app/Services/PayoutService.php:L31-L33` | Zero trips returns `'0.00'` — tested and passing |
| AC-06 | ✅ | `tests/Unit/PayoutServiceTest.php:L56` | `calculate('25.00', '10.00', 1)` → `'35.00'` — PASS |
| AC-07 | ✅ | `tests/Unit/PayoutServiceTest.php:L73` | `calculate('25.00', '10.00', 5)` → `'75.00'` — PASS |
| AC-08 | ✅ | `tests/Unit/PayoutServiceTest.php:L90` | `calculate('25.00', '10.00', 100)` → `'1025.00'` — PASS |
| AC-09 | ✅ | `tests/Unit/PayoutServiceTest.php:L107` | `calculate('0.00', '10.00', 3)` → `'30.00'` — PASS |
| AC-10 | ✅ | `tests/Unit/PayoutServiceTest.php:L124` | `calculate('25.00', '0.00', 3)` → `'25.00'` — PASS |
| AC-11 | ✅ | `tests/Unit/PayoutServiceTest.php:L141` | `calculate('25.00', '12.50', 7)` → `'112.50'` — PASS |
| AC-12 | ✅ | `tests/Unit/PayoutServiceTest.php:L158` | `calculate('0.00', '0.00', 0)` → `'0.00'` — PASS |
| AC-13 | ✅ | `tests/Unit/PayoutServiceTest.php:L175` | `calculate('0.00', '0.00', 5)` → `'0.00'` — PASS |
| AC-14 | ✅ | `tests/Unit/PayoutServiceTest.php:L192` | Negative trips throws `InvalidArgumentException` — PASS |
| AC-15 | ✅ | `tests/Unit/PayoutServiceTest.php:L212,L222` | `assertIsString` for zero and positive trip cases — PASS |
| AC-16 | ✅ | `tests/Unit/PayoutServiceTest.php:L236` | Regex `/^-?\d+\.\d{2}$/` via data provider — PASS (9 rows) |
| AC-17 | ✅ | `tests/Unit/RevenueServiceTest.php:L30` | Break-even: `'0.00'` — PASS |
| AC-18 | ✅ | `tests/Unit/RevenueServiceTest.php:L47` | Profit: `'25.00'` — PASS |
| AC-19 | ✅ | `tests/Unit/RevenueServiceTest.php:L65` | Loss: `'-25.00'` — PASS |
| AC-20 | ✅ | `tests/Unit/RevenueServiceTest.php:L82` | Zero trips: `'0.00'` — PASS |
| AC-21 | ✅ | `tests/Unit/RevenueServiceTest.php:L97,L107,L118` | `assertIsString` for all cases — PASS |
| AC-22 | ✅ | `app/Models/Biker.php:L9-L15` | `$fillable` contains all required fields |
| AC-23 | ✅ | `app/Models/Biker.php:L21` | `rate_per_trip` and `base_fee` cast as `decimal:2` |
| AC-24 | ✅ | `app/Models/ShiftBiker.php:L9-L15` | `$fillable` contains all required fields |
| AC-25 | ✅ | `app/Models/ShiftBiker.php:L21` | `biker_rate` and `base_fee` cast as `decimal:2` |
| AC-26 | ✅ | `app/Models/ShiftBiker.php:L28-L38` | `belongsTo(Shift::class)` and `belongsTo(Biker::class)` defined |
| AC-27 | ❌ | `tests/Unit/PayoutServiceTest.php` | Suite has 2 FAILING tests (see Finding F-01) |
| AC-28 | ✅ | `tests/Unit/RevenueServiceTest.php` | All 20 tests PASS |
| AC-29 | ✅ | `tests/Unit/PayoutServiceTest.php:L282` | `payoutFormulaProvider` has 12 dataset rows (≥10 required) |
| AC-30 | ❌ | Full suite | 2 failures — `php artisan test` exits with code 1 |

### Business Rules

| Rule | Enforced? | Enforcement Layer | Verified? |
|------|-----------|-------------------|-----------|
| BR-01 | N/A | Not in scope | — |
| BR-02 | N/A | Not in scope | — |
| BR-03 | ✅ | Service (`PayoutService::calculate`) | ✅ (formula correct; test expected value wrong) |
| BR-04 | N/A | Not in scope | — |
| BR-05 | N/A | Not in scope | — |
| BR-06 | N/A | Not in scope | — |

### Payout Formula Trace

- Implementation matches PRD: ✅
  - `PayoutService.php:L31-33`: `trips_count === 0` → return `'0.00'`
  - `PayoutService.php:L36-38`: `bcadd($baseFee, bcmul($bikerRate, (string)$tripsCount, 2), 2)`
  - This matches the PRD Section 3 formula exactly: `Base Fee + (Biker Rate × Trips)`
- `trips = 0` returns `'0.00'`: ✅
- Uses BCMath exclusively: ✅

### Revenue Formula Trace

- Implementation matches PRD: ✅
  - `RevenueService.php:L29-30`: `bcsub(bcmul($restaurantRate, (string)$tripsCount, 2), $payout, 2)`
  - This matches: `Revenue = (Restaurant Rate × Trips) - Payout`
- Zero trips returns `'0.00'`: ✅
- Returns string with 2 decimal places: ✅

### Findings

1. **F-01 (High)**: Two tests in `PayoutServiceTest` have an **incorrect expected value** for the "large numbers" edge case. The test expects `100089990.00` but the mathematically correct BCMath result is `100899990.00`. Verified:
   - `99999.99 × 999 = 99899990.01`
   - `999999.99 + 99899990.01 = 100899990.00`
   - The expected value `100089990.00` has a digit error (missing one `9`).
   - **The `PayoutService` code is correct. The test is wrong.**
   - Affected tests: `test_payout_formula_via_data_provider` (data set "large numbers") and `test_calculate_large_numbers_no_precision_loss`
   - Location: `tests/Unit/PayoutServiceTest.php:L274` (data provider) and `L360` (standalone test)

---

## Phase 2: Financial Accuracy

### Migration Audit

| Table | Column | Type | Correct? |
|-------|--------|------|----------|
| restaurants | rate_per_trip | DECIMAL(12,2) | ✅ |
| bikers | rate_per_trip | DECIMAL(12,2) | ✅ |
| bikers | base_fee | DECIMAL(12,2) | ✅ |
| shifts | restaurant_rate | DECIMAL(12,2) | ✅ |
| shift_bikers | biker_rate | DECIMAL(12,2) | ✅ |
| shift_bikers | base_fee | DECIMAL(12,2) | ✅ |
| shift_bikers | trips_count | INT UNSIGNED | ✅ |

**No FLOAT or DOUBLE columns found in any migration.** ✅

### Model Cast Audit

| Model | Field | Cast | Correct? |
|-------|-------|------|----------|
| Biker | rate_per_trip | decimal:2 | ✅ |
| Biker | base_fee | decimal:2 | ✅ |
| Biker | active | boolean | ✅ |
| Restaurant | rate_per_trip | decimal:2 | ✅ |
| Restaurant | active | boolean | ✅ |
| Shift | restaurant_rate | decimal:2 | ✅ |
| Shift | started_at | datetime | ✅ |
| Shift | closed_at | datetime | ✅ |
| ShiftBiker | biker_rate | decimal:2 | ✅ |
| ShiftBiker | base_fee | decimal:2 | ✅ |

**All financial fields correctly cast as `decimal:2`.** ✅

### Calculation Audit

| Service | Method | BCMath? | Scale 2? | No Float? |
|---------|--------|---------|----------|-----------|
| PayoutService | calculate() | ✅ bcmul + bcadd | ✅ Both use scale 2 | ✅ No `+`, `-`, `*`, `/` |
| RevenueService | calculate() | ✅ bcmul + bcsub | ✅ Both use scale 2 | ✅ No `+`, `-`, `*`, `/` |

### Manual Trace

**Test case:** 5 trips, base_fee=25.00, biker_rate=10.00

- Hand calculation: 25.00 + (10.00 × 5) = 25.00 + 50.00 = 75.00
- Code trace:
  - `bcmul('10.00', '5', 2)` → `'50.00'`
  - `bcadd('25.00', '50.00', 2)` → `'75.00'`
- Match: ✅

**Edge case:** trips_count = 0
- Code returns literal `'0.00'` at `PayoutService.php:L32`
- Verified: ✅ Returns string `'0.00'`, not `0`, `0.0`, `null`, or `'0'`

**Revenue trace:** restaurant_rate=20.00, trips=5, payout=75.00
- Hand: (20.00 × 5) - 75.00 = 100.00 - 75.00 = 25.00
- Code: `bcmul('20.00', '5', 2)` → `'100.00'`; `bcsub('100.00', '75.00', 2)` → `'25.00'`
- Match: ✅

### Findings

None. All financial calculations are precise, use BCMath with scale 2, and return string types.

---

## Phase 3: Security

### Container Integrity

- Docker Compose changes: **None.** The `docker-compose.yml` is unchanged — no new volumes, ports, or privilege escalation.
- New ports exposed: **None.** Still only `8000:8000` for app.
- Privilege escalation risk: **None.** No `privileged: true` or `network_mode: host`.
- `env_file` exposure: **None.**

### Input Validation

| Endpoint | Validation Present | Financial Bounds |
|----------|-------------------|-----------------|
| N/A — No HTTP endpoints | N/A | N/A |

> This plan is explicitly out of scope for HTTP routes. `PayoutService` and `RevenueService` are pure PHP classes with no controller exposure. The services validate `tripsCount >= 0` via guard clause. Input string validation is caller's responsibility, which is noted in the plan's Open Questions.

### Authorization

| Route | Required Role | Middleware | Effective |
|-------|--------------|------------|-----------|
| N/A | N/A | N/A | N/A |

No HTTP endpoints created — authorization not applicable to this phase.

### Data Exposure

- Mass assignment protection: ✅ All 4 models (`Biker`, `Restaurant`, `Shift`, `ShiftBiker`) have explicit `$fillable`. No `$guarded = []`.
- Credential leak risk: ✅ No hardcoded credentials, API keys, or secrets found.
- Unscoped queries: ✅ No `Model::all()` or unscoped queries in services (services don't touch the database at all).

### Findings

None. Security posture is clean for this phase.

---

## Phase 4: Database Integrity

- `migrate:fresh`: ✅ Clean — all 7 migrations run without errors
- All tables present: ✅ `restaurants`, `bikers`, `shifts`, `shift_bikers` confirmed
- Foreign keys correct: ✅
  - `shifts.restaurant_id` → `restaurants.id` (cascadeOnDelete)
  - `shift_bikers.shift_id` → `shifts.id` (cascadeOnDelete)
  - `shift_bikers.biker_id` → `bikers.id` (cascadeOnDelete)
- Indexes match plan: ✅ Unique index `shift_bikers_shift_id_biker_id_unique` on `(shift_id, biker_id)`
- Enum values correct: ✅ `workflow_type` VARCHAR(20) default `'live_tick'`, `status` VARCHAR(20) default `'open'`
- Defaults correct: ✅ All financial defaults are `'0.00'`, `trips_count` defaults to `0`

### Schema vs Plan

| Plan Table | Exists? | Columns Match? | Differences |
|------------|---------|----------------|-------------|
| restaurants | ✅ | ✅ | None |
| bikers | ✅ | ✅ | None |
| shifts | ✅ | ✅ | None |
| shift_bikers | ✅ | ✅ | None |

### Findings

None. Schema matches the plan exactly.

---

## Phase 5: Test Coverage

### Full Suite Result

```
Tests:    2 failed, 73 passed (101 assertions)
Duration: 0.65s

FAILED tests:
  - Tests\Unit\PayoutServiceTest > payout formula via data provider with data set "large numbers"
    Expected: '100089990.00', Got: '100899990.00'
  - Tests\Unit\PayoutServiceTest > calculate large numbers no precision loss
    Expected: '100089990.00', Got: '100899990.00'
```

### Coverage Matrix

| AC/BR | Test File | Test Method(s) | Present | Meaningful |
|-------|-----------|----------------|---------|------------|
| AC-01 | PayoutIntegrationTest | `test_migrations_create_all_required_tables` | ✅ | ✅ |
| AC-02 | PayoutIntegrationTest | `test_biker_model_stores_decimal_rates`, `test_biker_model_defaults_rates_to_zero` | ✅ | ✅ |
| AC-03 | PayoutIntegrationTest | `test_shift_biker_stores_formula_inputs`, `test_shift_biker_trips_count_defaults_to_zero` | ✅ | ✅ |
| AC-04 | PayoutIntegrationTest | `test_shift_biker_unique_constraint_prevents_duplicate_assignment` | ✅ | ✅ |
| AC-05 | PayoutServiceTest | `test_calculate_with_zero_trips_returns_zero_string` | ✅ | ✅ |
| AC-06 | PayoutServiceTest | `test_calculate_with_one_trip_returns_base_fee_plus_one_rate` | ✅ | ✅ |
| AC-07 | PayoutServiceTest | `test_calculate_with_five_trips_returns_correct_total` | ✅ | ✅ |
| AC-08 | PayoutServiceTest | `test_calculate_with_hundred_trips_returns_large_total` | ✅ | ✅ |
| AC-09 | PayoutServiceTest | `test_calculate_with_zero_base_fee_returns_rate_times_trips` | ✅ | ✅ |
| AC-10 | PayoutServiceTest | `test_calculate_with_zero_biker_rate_returns_base_fee_only` | ✅ | ✅ |
| AC-11 | PayoutServiceTest | `test_calculate_with_decimal_rate_returns_precise_result` | ✅ | ✅ |
| AC-12 | PayoutServiceTest | `test_calculate_with_all_zeroes_zero_trips_returns_zero` | ✅ | ✅ |
| AC-13 | PayoutServiceTest | `test_calculate_with_all_zeroes_positive_trips_returns_zero` | ✅ | ✅ |
| AC-14 | PayoutServiceTest | `test_calculate_with_negative_trips_*` (2 tests) | ✅ | ✅ |
| AC-15 | PayoutServiceTest | `test_calculate_returns_string_type_*` (2 tests) | ✅ | ✅ |
| AC-16 | PayoutServiceTest | `test_calculate_result_has_exactly_two_decimal_places` (9-row provider) | ✅ | ✅ |
| AC-17 | RevenueServiceTest | `test_calculate_break_even_returns_zero` | ✅ | ✅ |
| AC-18 | RevenueServiceTest | `test_calculate_profit_returns_positive` | ✅ | ✅ |
| AC-19 | RevenueServiceTest | `test_calculate_loss_returns_negative` | ✅ | ✅ |
| AC-20 | RevenueServiceTest | `test_calculate_zero_trips_returns_zero` | ✅ | ✅ |
| AC-21 | RevenueServiceTest | `test_calculate_returns_string_type_*` (3 tests) | ✅ | ✅ |
| AC-22 | PayoutIntegrationTest | `test_biker_model_has_required_fillable_fields` | ✅ | ✅ |
| AC-23 | PayoutIntegrationTest | `test_biker_model_casts_financial_fields_as_decimal` | ✅ | ✅ |
| AC-24 | PayoutIntegrationTest | `test_shift_biker_model_has_required_fillable_fields` | ✅ | ✅ |
| AC-25 | PayoutIntegrationTest | `test_shift_biker_model_casts_financial_fields_as_decimal` | ✅ | ✅ |
| AC-26 | PayoutIntegrationTest | `test_shift_biker_belongs_to_shift`, `test_shift_biker_belongs_to_biker` | ✅ | ✅ |
| AC-27 | PayoutServiceTest | — | ❌ | Suite has 2 FAILING tests |
| AC-28 | RevenueServiceTest | — | ✅ | All 20 tests PASS |
| AC-29 | PayoutServiceTest | `payoutFormulaProvider` (12 rows) | ✅ | ✅ |
| AC-30 | Full suite | — | ❌ | 2 failures |

### Test Categories

- Formula tests (payout): ✅ 34 test methods in `PayoutServiceTest`
- Formula tests (revenue): ✅ 20 test methods in `RevenueServiceTest`
- Boundary tests (0, 1, negative): ✅ Multiple dedicated boundary tests
- State transition tests: N/A (not in scope — no state machine)
- Authorization tests: N/A (no HTTP endpoints)
- Audit trail tests: N/A (not in scope)
- Integration tests: ✅ 16 tests in `PayoutIntegrationTest`

### Test Quality

- Financial assertions use string comparison: ✅ (`assertEquals` with string literals)
- No `markTestSkipped()` or `markTestIncomplete()`: ✅
- No vacuous assertions: ✅
- Test factories use explicit financial values: ✅ (no random data)
- Data provider ≥10 rows: ✅ (12 rows in `payoutFormulaProvider`, 9 in `revenueFormulaProvider`)
- Full suite GREEN: ❌ (2 failures)

### Findings

See Finding F-01 above — same root cause. The tests for the "large numbers" edge case have an incorrect expected value.

---

## Phase 6: Regression

- Full suite on clean slate (`migrate:fresh && test`): ❌ 2 failures (same as above)
- Previously validated features: ✅ `ExampleTest` (unit + feature) still pass
- No migration rollback issues: ✅ All `down()` methods correctly drop tables
- Integration tests (database layer): ✅ All 16 integration tests PASS

### Findings

The 2 failures are not regressions — they are new tests with an incorrect expected value for a new edge case.

---

## All Findings

| # | Phase | Severity | Description | Location | Action Required |
|---|-------|----------|-------------|----------|-----------------|
| 1 | Phase 1/5 | **High** | Two tests have incorrect expected value for the "large numbers" edge case. Test expects `100089990.00` but the mathematically correct result of `999999.99 + (99999.99 × 999)` is `100899990.00`. The PayoutService code is correct. | `tests/Unit/PayoutServiceTest.php:L274` (data provider row "large numbers") and `tests/Unit/PayoutServiceTest.php:L360` (standalone test) | **Tester** must fix the expected value from `'100089990.00'` to `'100899990.00'` in both locations |

---

## Recommendation

### Verdict: 🔴 FAIL

The implementation code (`PayoutService`, `RevenueService`, all models, all migrations) is **correct and PRD-compliant**. The BCMath financial arithmetic is precise. The schema matches the plan. Security is intact.

However, the test suite has **2 failing tests** due to an incorrect expected value in the test, not a code defect. Per the Gate Check rule: *"If tests are not GREEN, validation does not start."*

### Routed Findings

| Finding # | Route To | Reason |
|-----------|----------|--------|
| F-01 | **Tester** | Fix the expected value in `tests/Unit/PayoutServiceTest.php`: change `'100089990.00'` to `'100899990.00'` in (1) `payoutFormulaProvider` data set "large numbers" at line 274, and (2) `test_calculate_large_numbers_no_precision_loss` at line 360 |

### Post-Fix Expectation

Once the Tester corrects the two expected values:
- `payoutFormulaProvider['large numbers']`: `'100089990.00'` → `'100899990.00'`
- `test_calculate_large_numbers_no_precision_loss`: `'100089990.00'` → `'100899990.00'`

The full suite should pass with **75 tests, 103 assertions, 0 failures**. At that point, this implementation is ready for an immediate re-audit with an expected **🟢 PASS** verdict.

