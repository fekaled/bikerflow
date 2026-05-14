# BikerFlow — Audit Checklist

This is the Validator's master checklist. Every item must be checked for every audit. No exceptions.

## Phase 1: PRD Compliance

### Acceptance Criteria Traceability

For each AC-XX in the plan:

- [ ] AC identified and read from plan
- [ ] Corresponding code located (file + line reference)
- [ ] Code implements the criterion as described (not loosely)
- [ ] Corresponding test exists and tests the right behavior
- [ ] Result: ✅ Compliant or ❌ Deviation

### Business Rule Enforcement

For each BR-XX flagged in the plan:

| Rule | Code Enforced? | Enforcement Layer | Test Exists? | Test Meaningful? |
|------|---------------|-------------------|-------------|-----------------|
| BR-01 Workflow Locking | | | | |
| BR-02 PIX Verification | | | | |
| BR-03 Manual Release | | | | |
| BR-04 Granular Failure | | | | |
| BR-05 Last Minute Biker | | | | |
| BR-06 Payment Retries | | | | |

### Payout Formula Verification

- [ ] `trips_count = 0` → returns `'0.00'` (string)
- [ ] `trips_count > 0` → returns `bcadd(base_fee, bcmul(biker_rate, trips_count, 2), 2)`
- [ ] No other formula variant is used anywhere in the codebase
- [ ] The plan's pseudocode matches the actual implementation

### Revenue Formula Verification

- [ ] `Revenue = (restaurant_rate × trips_count) - Payout`
- [ ] Uses `bcsub` and `bcmul`, not arithmetic operators
- [ ] Returns string with 2 decimal places

---

## Phase 2: Financial Accuracy

### Migration Column Types

For every table with monetary columns:

| Table | Column | Type | Correct (DECIMAL 12,2)? |
|-------|--------|------|------------------------|
| | | | |

### Model Casts

For every model with monetary fields:

| Model | Field | Cast | Correct (decimal:2)? |
|-------|-------|------|----------------------|
| | | | |

### Calculation Audit

For every service method doing math:

| Service | Method | Uses BCMath? | Scale 2? | No float ops? |
|---------|--------|-------------|----------|---------------|
| | | | | |

### Manual Trace

Test case: _______

- [ ] Input values identified
- [ ] Step-by-step code trace performed
- [ ] Expected result calculated by hand
- [ ] Code produces identical result
- [ ] Edge case `trips = 0` verified manually

---

## Phase 3: Security

### Container Integrity

- [ ] `docker-compose.yml` — no new host volume mounts outside project
- [ ] No new ports exposed beyond 8000, 3306
- [ ] No `privileged: true` or `network_mode: host`
- [ ] No `env_file` pointing to host secrets

### Input Validation

| Endpoint | Method | Has Form Request? | Validates Financial Fields? | Has min/max Bounds? |
|----------|--------|-------------------|----------------------------|---------------------|
| | | | | |

### Authorization

| Route | Required Role | Middleware Present? | Check Effective? |
|-------|--------------|--------------------|-----------------|
| | | | |

### Data Exposure

- [ ] No `Model::all()` without scoping
- [ ] No API response leaking fields beyond plan scope
- [ ] No hardcoded credentials or API keys
- [ ] All models have `$fillable` defined
- [ ] No `$guarded = []` (mass assignment vulnerability)

---

## Phase 4: Database Integrity

- [ ] `php artisan migrate:fresh` completes without error
- [ ] All tables from plan exist: `php artisan tinker --execute="echo implode(', ', Schema::getTableListing());"`
- [ ] All columns have correct types
- [ ] Foreign keys present and correct
- [ ] Cascade rules match plan (cascadeOnDelete vs restrictOnDelete)
- [ ] Indexes match plan specification
- [ ] Enum columns have correct value sets
- [ ] Defaults are correct (especially financial defaults to `'0.00'`)
- [ ] No orphaned or unused tables/columns

---

## Phase 5: Test Coverage

### Coverage Matrix

| AC/BR | Test File | Test Method | Tests Right Thing? | Assertion Meaningful? |
|-------|-----------|-------------|--------------------|-----------------------|
| | | | | |

### Test Categories Present

- [ ] Formula tests (payout, revenue)
- [ ] Boundary tests (0, 1, max, negative)
- [ ] State transition tests
- [ ] Authorization tests
- [ ] Audit trail tests
- [ ] Missing categories: _______

### Test Quality

- [ ] Financial assertions use string comparison
- [ ] No `markTestSkipped()` or `markTestIncomplete()`
- [ ] No `assertTrue(true)` or vacuous assertions
- [ ] Test factories use explicit financial values (not random)
- [ ] Full suite: `php artisan test` — ALL GREEN

---

## Phase 6: Regression

- [ ] Full test suite passes
- [ ] Previously ✅/🟢 features still pass
- [ ] No migration rollback issues
- [ ] `php artisan migrate:fresh && php artisan test` — clean slate passes

---

## Phase 7: Verdict

### Findings Summary

| # | Phase | Severity | Description | File/Location |
|---|-------|----------|-------------|---------------|
| | | Critical/High/Medium/Low | | |

### Verdict

- [ ] **PASS** — Zero Critical or High findings
- [ ] **PASS WITH CONDITIONS** — Only Medium or Low findings
- [ ] **FAIL** — One or more Critical or High findings

### If FAIL — Route Back

| Finding # | Routed To | Reason |
|-----------|-----------|--------|
| | Planner / Developer / Tester | |
