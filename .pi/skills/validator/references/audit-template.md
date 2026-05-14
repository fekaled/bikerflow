# Audit Report Template

Use this exact structure for every audit report. Fill every section. If a section has no findings, write "No findings in this phase" rather than omitting it.

---

```markdown
# Audit Report: <Title>

**Task ID:** <US-XX, BR-XX, or custom identifier>
**Date:** <YYYY-MM-DD>
**Auditor:** Validator Agent
**Plan Reference:** `docs/plans/<plan-file>.md`
**Test Suite Status:** GREEN / RED (if RED, audit does not proceed)

---

## Verdict

**🟢 PASS** / **🟡 PASS WITH CONDITIONS** / **🔴 FAIL**

### Findings Summary

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

---

## Phase 1: PRD Compliance

### Acceptance Criteria

| AC | Status | Code Location | Notes |
|----|--------|---------------|-------|
| AC-01 | ✅ / ❌ | `path/to/file.php:L42` | |
| AC-02 | ✅ / ❌ | | |

### Business Rules

| Rule | Enforced? | Enforcement Layer | Verified? |
|------|-----------|-------------------|-----------|
| BR-01 | ✅ / ❌ | Service / Middleware / DB | ✅ / ❌ |
| BR-02 | ✅ / ❌ | | |
| BR-03 | ✅ / ❌ | | |
| BR-04 | ✅ / ❌ | | |
| BR-05 | ✅ / ❌ | | |
| BR-06 | ✅ / ❌ | | |

### Payout Formula Trace

- Implementation matches PRD: ✅ / ❌
- `trips = 0` returns `'0.00'`: ✅ / ❌
- Uses BCMath exclusively: ✅ / ❌
- Details: <any observations>

### Revenue Formula Trace

- Implementation matches PRD: ✅ / ❌
- Details: <any observations>

### Findings

<Numered list of deviations, or "None.">

---

## Phase 2: Financial Accuracy

### Migration Audit

| Table | Column | Type | Correct? |
|-------|--------|------|----------|
| | | DECIMAL(12,2) | ✅ / ❌ |

### Model Cast Audit

| Model | Field | Cast | Correct? |
|-------|-------|------|----------|
| | | decimal:2 | ✅ / ❌ |

### Calculation Audit

| Service | Method | BCMath? | Scale 2? | No Float? |
|---------|--------|---------|----------|-----------|
| | | ✅ / ❌ | ✅ / ❌ | ✅ / ❌ |

### Manual Trace

**Test case:** <e.g., 5 trips, base_fee=25.00, rate=10.00>

- Hand calculation: <e.g., 25.00 + (10.00 × 5) = 75.00>
- Code output: <e.g., 75.00>
- Match: ✅ / ❌

### Findings

<Numbered list, or "None.">

---

## Phase 3: Security

### Container Integrity

- Docker Compose changes: <none / list changes>
- New ports exposed: <none / list>
- Privilege escalation risk: <none / describe>

### Input Validation

| Endpoint | Validation Present | Financial Bounds |
|----------|-------------------|-----------------|
| | ✅ / ❌ | ✅ / ❌ |

### Authorization

| Route | Required Role | Middleware | Effective |
|-------|--------------|------------|-----------|
| | | ✅ / ❌ | ✅ / ❌ |

### Data Exposure

- Mass assignment protection: ✅ / ❌
- Credential leak risk: ✅ / ❌
- Unscoped queries: ✅ / ❌

### Findings

<Numbered list, or "None.">

---

## Phase 4: Database Integrity

- `migrate:fresh`: ✅ Clean / ❌ Errors
- All tables present: ✅ / ❌
- Foreign keys correct: ✅ / ❌
- Indexes match plan: ✅ / ❌
- Enum values correct: ✅ / ❌

### Schema vs Plan

| Plan Table | Exists? | Columns Match? | Differences |
|------------|---------|----------------|-------------|
| | ✅ / ❌ | ✅ / ❌ | |

### Findings

<Numbered list, or "None.">

---

## Phase 5: Test Coverage

### Full Suite Result

```
<paste of php artisan test -v output>
```

### Coverage Matrix

| AC/BR | Test File | Test Method | Present | Meaningful |
|-------|-----------|-------------|---------|------------|
| | | | ✅ / ❌ | ✅ / ❌ |

### Test Categories

- Formula tests: ✅ Present / ❌ Missing
- Boundary tests: ✅ Present / ❌ Missing
- State transition tests: ✅ Present / ❌ Missing
- Authorization tests: ✅ Present / ❌ Missing
- Audit trail tests: ✅ Present / ❌ Missing

### Findings

<Numbered list, or "None.">

---

## Phase 6: Regression

- Full suite on clean slate: ✅ / ❌
- Previously validated features: ✅ Intact / ❌ Broken
- Details: <any observations>

### Findings

<Numbered list, or "None.">

---

## All Findings

| # | Phase | Severity | Description | Location | Action Required |
|---|-------|----------|-------------|----------|-----------------|
| 1 | | Critical/High/Medium/Low | | `file:L##` | |
| 2 | | | | | |

---

## Recommendation

- **If PASS:** Feature is approved for merge to `main`.
- **If PASS WITH CONDITIONS:** List conditions for user review.
- **If FAIL:** Route findings to the appropriate agent with specific instructions.

### Routed Findings (if FAIL)

| Finding # | Route To | Reason |
|-----------|----------|--------|
| | Planner / Developer / Tester | |
```

---

**End of Template.** The Validator fills this template and saves it to `docs/audits/<task-id>-audit.md`.
