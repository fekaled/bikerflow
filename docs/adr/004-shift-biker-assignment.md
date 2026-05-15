# ADR-004: Shift-Biker Assignment — Admin Biker Management with Status Guards

**Date:** 2026-05-14
**Status:** ✅ Accepted
**Decision Maker:** Planner / Developer / Validator
**Task ID:** Phase-2C
**Pipeline:** phase-2c-shift-biker-assignment
**Business Rules:** BR-01, BR-05
**Related Plan:** `docs/plans/phase-2c-shift-biker-assignment.md`

---

## Context

Phase 2B established Shift CRUD & Lifecycle management. However, the Admin still lacked the ability to assign, update, and remove bikers from shifts — a core workflow needed before payouts can be calculated. The `shift_bikers` pivot table existed from Phase 1 but had no controller, routes, or UI for managing assignments. Business Rule BR-05 requires that only Admins can add/remove bikers from shifts, and BR-01 requires that assignments are only allowed on draft or open shifts.

---

## Decision

1. **ShiftBikerController** in `Admin` namespace with 4 actions (index, store, update, destroy) — nested under shifts.
2. **AssignBikerRequest** form request validates store: biker must exist, be active, not already assigned; shift must be draft/open; financial fields numeric min:0.
3. **UpdateShiftBikerRequest** form request validates update: financial fields + trips_count; shift must be draft/open.
4. **Nested web routes** under `shifts/{shift}/bikers` — protected by `auth` + `role:admin` middleware + ShiftPolicy authorization.
5. **Blade partial** (`biker-assignments.blade.php`) embedded in shift show view — conditionally shows forms/buttons based on shift mutability.
6. **Defense-in-depth:** Authorization enforced at middleware, policy (ShiftPolicy@addBiker), and form request levels. Controller also guards cross-shift ShiftBiker manipulation.

---

## Alternatives Considered

| # | Alternative | Pros | Cons | Why Rejected |
|---|-------------|------|------|--------------|
| 1 | API-only (JSON) routes | Cleaner for SPAs | PRD specifies Blade+Vite; no SPA | Out of scope |
| 2 | Bulk biker assignment | Fewer round-trips | Not in PRD; added complexity | Deferred |
| 3 | Soft deletes on ShiftBiker removal | Audit trail | Over-engineering; payment records preserve history | Not needed |
| 4 | Separate page for biker management | More space | Context loss — admins need to see shift details | Partial embedded approach chosen |

---

## Consequences

### Positive

- Admin can manage biker assignments within the shift detail context
- BR-05 enforced at 3 layers (middleware + policy + form request)
- BR-01 enforced at 2 layers (form request + controller defense-in-depth)
- No new migrations or schema changes — uses existing shift_bikers table
- Defaults biker_rate/base_fee from Biker model when omitted — reduces admin data entry
- All 47 ACs covered by 47+ feature tests

### Negative

- Inline Biker::where query in Blade partial (cosmetic, minor — noted as finding L-02)
- Edit form currently submits PATCH with no fields — needs JavaScript for inline editing in future enhancement

### Risks

- Concurrent duplicate assignment relies on DB unique constraint as safety net
- Biker rate/base_fee defaults are snapshotted at assignment time — if Biker model changes later, existing assignments are unaffected (by design)

---

## Artefacts Affected

| Type | File | Change |
|------|------|--------|
| Controller | `app/Http/Controllers/Admin/ShiftBikerController.php` | Created |
| Request | `app/Http/Requests/AssignBikerRequest.php` | Created |
| Request | `app/Http/Requests/UpdateShiftBikerRequest.php` | Created |
| View | `resources/views/shifts/partials/biker-assignments.blade.php` | Created |
| View | `resources/views/shifts/show.blade.php` | Modified (include partial) |
| Route | `routes/web.php` | Modified (nested shift-biker routes) |
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | Modified (eager-load shiftBikers.biker) |
| Test | `tests/Feature/Controllers/ShiftBikerControllerTest.php` | Created |

---

## Acceptance Criteria Covered

- AC-2C-01 through AC-2C-47: 47 acceptance criteria covering routes, authorization, store, update, destroy, views, and regression.

---

## References

- Plan: `docs/plans/phase-2c-shift-biker-assignment.md`
- Pipeline manifest: `docs/archives/pipelines/phase-2c-shift-biker-assignment.json`
- PRD Section 2C, Section 4 (BR-05)

---

_See [ADR Index](./README.md) for all decisions._
