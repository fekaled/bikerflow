# Plan Template

Use this exact structure for every plan document. Fill every section. If a section is not applicable, write "N/A — no changes in this area" rather than omitting it.

---

```markdown
# Plan: <Title>

**Task ID:** <US-XX, BR-XX, or custom identifier>
**Date:** <YYYY-MM-DD>
**Planner Version:** 1.0
**Complexity:** Simple | Medium | Complex

---

## 1. Objective

<One paragraph describing what this plan achieves and why.>

---

## 2. Source References

### User Stories
- <US-XX: brief description>

### Business Rules
- <BR-XX: brief description of how it applies>

### PRD Sections
- <Section number and title from docs/bikerflow-prd.md>

### Tech Doc Sections
- <Section number and title from docs/bikerflow_technical_documentation.md>

---

## 3. Scope

### In Scope
- <Numbered list of what this plan covers>

### Out of Scope
- <Numbered list of what is explicitly excluded>

### Open Questions
- <Numbered list of ambiguities requiring user decision. Remove section if none.>

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | Yes/No | <How this rule constrains the implementation> |
| BR-02 PIX Verification | Yes/No | <How this rule constrains the implementation> |
| BR-03 Manual Release | Yes/No | <How this rule constrains the implementation> |
| BR-04 Granular Failure | Yes/No | <How this rule constrains the implementation> |
| BR-05 Last Minute Biker | Yes/No | <How this rule constrains the implementation> |
| BR-06 Payment Retries | Yes/No | <How this rule constrains the implementation> |

---

## 5. Schema Changes

### New Tables

<If none, write "No new tables.">

```
table_name
├── column_name     TYPE(NULLABLE)    — description
├── ...
└── timestamps
```

### Modified Tables

<If none, write "No modifications.">

```
table_name
├── + new_column      TYPE(NULLABLE)    — description
├── ~ changed_column   TYPE → TYPE       — reason for change
└── timestamps
```

### Indexes

<If none, write "No new indexes.">

- `index_name` on `table(columns)` — reason

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| <column> | <table> | DECIMAL(12,2) | Yes |

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Migration | `database/migrations/....php` | <description> |
| Model | `app/Models/....php` | <description> |
| Controller | `app/Http/Controllers/....php` | <description> |
| View | `resources/views/....blade.php` | <description> |
| Service | `app/Services/....php` | <description> |
| Test | `tests/Feature/....php` | <description> |
| Route | `routes/web.php` or `routes/api.php` | <description> |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| <layer> | `path/to/file.php` | <what changes and why> |

---

## 7. Pseudocode

### Critical Business Logic

<Structured pseudocode for the most complex parts. Focus on payout calculations, state transitions, and permission checks.>

```
FUNCTION calculatePayout(shift, biker):
    IF shift.trips_count == 0:
        RETURN 0.00
    ELSE:
        RETURN bcadd(
            shift.base_fee,
            bcmul(shift.biker_rate, shift.trips_count, 2),
            2
        )
```

### State Transitions

<If the feature involves status changes, diagram them.>

```
[Status A] ──(action)──▶ [Status B] ──(action)──▶ [Status C]
                          │
                          └──(failure)──▶ [Status D]
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware |
|--------|-----|-------------------|------|------------|
| GET | `/path` | `Controller@index` | <who> | `<middleware>` |

---

## 8. Edge Cases

1. <Numbered list of scenarios the Developer must handle>
2. <e.g., "Biker with 0 trips — payout must be exactly 0.00, not NULL">
3. <e.g., "Concurrent shift close attempts by same restaurant">

---

## 9. Acceptance Criteria

These are the **exact conditions** the Tester will verify. Each must be atomic and unambiguous.

- [ ] AC-01: <description>
- [ ] AC-02: <description>
- [ ] AC-03: <description>

---

## 10. Security Considerations

- **Authorization:** <who can access what>
- **Input Validation:** <what must be validated>
- **Container Compliance:** <confirmation that no step requires external access>
- **Financial Safety:** <how precision is maintained, how double-pay is prevented>
```

---

**End of Template.** The Planner fills this template and saves it to `docs/plans/<task-id>-<slug>.md`.
