# ADR-001: Core Payout Schema — Entities, Enums & State Machine

**Date:** 2026-05-14
**Status:** Accepted
**Decision Maker:** Planner (blueprint), Developer (implementation), Validator (audit)
**Task ID:** phase-1-core-schema
**Pipeline:** full-tdd-phase-1-core-schema-20260514104257
**Business Rules:** BR-01, BR-02, BR-03, BR-04, BR-06
**Related Plan:** `docs/plans/phase-1-core-schema-payout.md`

---

## Context

BikerFlow needs a foundational data layer to support a restaurant-to-biker payout management system. Before any controllers, routes, or UI can be built, the database schema, Eloquent models, and business-rule enforcement must be established.

Three key open questions had to be resolved before implementation:

1. **Auth/User FK references** — Should `created_by` / `released_by` columns be added now or deferred until the auth system exists?
2. **Trip-level granularity** — Should trips be tracked individually (one row per trip) or as an aggregate counter?
3. **Shift draft state** — Should shifts start as `open` (immediately active) or as `draft` (pre-configurable)?

---

## Decision

### Schema: 7 Tables

We adopted a normalized relational schema with 7 tables: `restaurants`, `bikers`, `shifts`, `shift_bikers`, `pix_keys`, `payments`, `payment_audit_logs`.

**Key design choices:**

1. **Auth FK columns included now** (`created_by`, `released_by`) — nullable, no foreign key constraint until auth is implemented. Avoids a later migration.
2. **Aggregate `trips_count`** on `shift_bikers` (UNSIGNED INT, default 0) — sufficient for Phase 1. Individual trip tracking deferred to a future phase if business need arises.
3. **Option C: Draft with pre-selected workflow** — Shifts default to `status = 'draft'` with a `workflow_type` that is freely editable. On `draft → open` transition, `started_at` is set and `workflow_type` becomes immutable (BR-01). This is a state machine with transitions: `draft → open → closed → approved → paid`.

### Enums: 5 PHP Backed Enums

All status/type columns use PHP 8.1 backed string enums (not database enums) for type safety, discoverability, and testability:

- `ShiftStatus`: draft, open, closed, approved, paid
- `WorkflowType`: live_tick, manual_entry
- `PaymentStatus`: pending, processing, paid, failed
- `PixKeyType`: cpf, phone, email, random
- `PaymentAuditAction`: create, release, attempt, retry, fail, succeed

### Financial Precision: BCMath Scale 2

All financial columns are `DECIMAL(12,2)`. All calculations use BCMath with scale 2. No float arithmetic touches money. Services (`PayoutService`, `RevenueService`) return PHP strings.

### BR-01 Enforcement: Model-Level Hook

Workflow locking (BR-01) is enforced in `Shift::boot()` via a `saving` model event — not at the database or controller level. This ensures the rule is always active regardless of entry point.

### Snapshotted Rates

`shift_bikers` stores `biker_rate` and `base_fee` at assignment time, insulating calculations from future rate changes on the `biker` or `restaurant` records.

---

## Alternatives Considered

| # | Alternative | Pros | Cons | Why Rejected |
|---|-------------|------|------|--------------|
| 1 | **Option A: No draft state** — Shifts start as `open` immediately | Simpler — fewer states | Cannot pre-configure workflow_type or add bikers before shift starts; contradicts PRD's manual flow | PRD implies a preparation step; Option C matches user mental model |
| 2 | **Option B: Draft without workflow** — `workflow_type` is NULL in draft, set on open | Cleaner separation | Requires nullable enum, extra validation on transition, more complex model logic | Option C is simpler: pre-select workflow, lock it on open |
| 3 | **Individual trip rows** instead of `trips_count` counter | Full audit trail per trip | Over-engineering for Phase 1; PRD only requires aggregate payout; significantly more complex queries | `trips_count` aggregate is sufficient; can migrate later if needed |
| 4 | **Database-level enums** (MySQL ENUM) | Database-enforced values | Not portable; requires migration for every value change; PHP enums provide same safety with testability | PHP backed enums are more flexible and testable |
| 5. | **Controller-level BR-01 enforcement** | Centralized, easy to disable | Bypass-able via artisan tinker, queue jobs, or other controllers | Model-level hook ensures rule is always enforced regardless of entry point |

---

## Consequences

### Positive

- **Type-safe state machine** — The `ShiftStatus` enum and model-level guards prevent invalid state transitions at the ORM layer.
- **Financial precision guaranteed** — BCMath + `DECIMAL(12,2)` eliminates floating-point rounding errors on BRL values.
- **Business rules testable without HTTP** — All BR enforcement is in models and services, enabling fast unit tests.
- **Future-proof auth columns** — `created_by` and `released_by` are already in place, avoiding a schema change later.
- **Snapshotted rates protect historical accuracy** — Payout calculations reflect rates at assignment time, not current rates.
- **Clear separation of concerns** — Enums handle states, models handle relationships + guards, services handle calculations.

### Negative

- **Draft state adds complexity** — 5-state shift lifecycle (draft → open → closed → approved → paid) instead of 4.
- **Model-level BR-01 not enforced at DB level** — A raw SQL UPDATE could bypass the guard. Acceptable for Phase 1; a DB trigger or application-level policy can be added later.
- **Aggregate trips_count loses per-trip detail** — Cannot reconstruct individual trip timestamps from Phase 1 data alone.
- **Nullable auth columns without FK constraint** — Data integrity relies on application logic until auth is implemented.

### Risks

- **State transition bugs** — The shift state machine has 5 states with specific allowed transitions. Edge cases (e.g., concurrent requests) must be tested carefully in Phase 2.
- **Migration risk on draft default** — Changing `status` default from `open` to `draft` means any code assuming shifts start as `open` will break. All such code paths must be updated.

---

## Artefacts Affected

| Type | File | Change |
|------|------|--------|
| Migration | `database/migrations/2026_05_14_000001_create_restaurants_table.php` | Pre-existing |
| Migration | `database/migrations/2026_05_14_000002_create_bikers_table.php` | Pre-existing |
| Migration | `database/migrations/2026_05_14_000003_create_shifts_table.php` | Modified (draft default, nullable started_at, created_by, indexes) |
| Migration | `database/migrations/2026_05_14_000004_create_shift_bikers_table.php` | Pre-existing |
| Migration | `database/migrations/2026_05_14_000005_create_pix_keys_table.php` | Created |
| Migration | `database/migrations/2026_05_14_000006_create_payments_table.php` | Created |
| Migration | `database/migrations/2026_05_14_000007_create_payment_audit_logs_table.php` | Created |
| Model | `app/Models/Restaurant.php` | Modified (relationships) |
| Model | `app/Models/Biker.php` | Modified (relationships) |
| Model | `app/Models/Shift.php` | Modified (relationships, enum casts, BR-01 hook) |
| Model | `app/Models/ShiftBiker.php` | Modified (relationships) |
| Model | `app/Models/PixKey.php` | Created |
| Model | `app/Models/Payment.php` | Created |
| Model | `app/Models/PaymentAuditLog.php` | Created |
| Enum | `app/Enums/ShiftStatus.php` | Created |
| Enum | `app/Enums/WorkflowType.php` | Created |
| Enum | `app/Enums/PaymentStatus.php` | Created |
| Enum | `app/Enums/PixKeyType.php` | Created |
| Enum | `app/Enums/PaymentAuditAction.php` | Created |
| Exception | `app/Exceptions/WorkflowLockedException.php` | Created |
| Service | `app/Services/PayoutService.php` | Pre-existing, untouched |
| Service | `app/Services/RevenueService.php` | Pre-existing, untouched |
| Factory | `database/factories/*.php` (7 factories) | Created |
| Test | `tests/Feature/Models/*` (7 test files) | Created |
| Test | `tests/Unit/PayoutServiceTest.php` | Fixed (2 expected values) |
| Test | `tests/Unit/Enums/EnumTest.php` | Created |
| Test | `tests/Feature/Factories/FactoryTest.php` | Created |

---

## Acceptance Criteria Covered

All 41 acceptance criteria (AC-01 through AC-41, including AC-36a through AC-38a for Option C draft state).

---

## References

- Plan: `docs/plans/phase-1-core-schema-payout.md`
- Audit: `docs/audits/phase-1-core-schema-audit.md`
- Pipeline manifest: `docs/archives/pipelines/full-tdd-phase-1-core-schema-20260514104257.json`
- PRD Sections 2–5 (User Personas, Rate & Revenue Management, Business Rules, Functional Requirements)
- Tech Doc Sections 1, 3, 5 (Tech Stack, Business Logic & Formulas, Security & Guardrails)

---

_See [ADR Index](./README.md) for all decisions._
