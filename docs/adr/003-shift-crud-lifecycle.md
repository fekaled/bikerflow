# ADR-003: Shift CRUD & Lifecycle — Admin Shift Management with Workflow Locking

**Date:** 2026-05-14
**Status:** Accepted
**Decision Maker:** Planner (blueprint), Developer (implementation), Validator (audit)
**Task ID:** phase-2b
**Pipeline:** full-tdd-phase-2b-shift-crud-lifecycle
**Business Rules:** BR-01, BR-05
**Related Plan:** `docs/plans/phase-2b-shift-crud-lifecycle.md`

---

## Context

BikerFlow requires a complete Shift CRUD lifecycle for Admin users. Shifts are the core operational unit — they connect a restaurant to a tracking method and rate. The Admin creates shifts, chooses a tracking method (live_tick or manual_entry), views and edits them, and closes them when complete.

Key constraints from the PRD and existing architecture:

1. **BR-01 Workflow Locking** — The tracking method (`workflow_type`) is immutable once a shift leaves draft status. This is already enforced at the model layer (Shift `saving` hook), but needs request-level and UI-level enforcement too.
2. **BR-05 Admin-Only** — Only Admin can manage shifts. This is enforced via `role:admin` middleware and `ShiftPolicy`.
3. **Existing schema** — All required columns already exist on the `shifts` table from Phase 1. No new migrations needed.
4. **State lifecycle** — Shifts follow: `draft` → `open` → `closed`. Only Admin can transition states.

---

## Decision

### ShiftController (Admin Namespace)

Place `ShiftController` in `App\Http\Controllers\Admin\` namespace with 7 actions: `index`, `create`, `store`, `show`, `edit`, `update`, `close`. All wrapped in `auth` + `role:admin` middleware.

### Three-Layer BR-01 Enforcement

Workflow locking is enforced at three layers:

1. **Request layer** — `UpdateShiftRequest` custom closure rule rejects `workflow_type` changes on non-draft shifts with user-friendly error.
2. **Model layer** — `Shift::saving` hook (authoritative) throws `WorkflowLockedException` if `isDirty('workflow_type')` on non-draft.
3. **UI layer** — Edit view shows `workflow_type` as read-only/disabled for non-draft shifts.

### Dedicated Form Requests

- **StoreShiftRequest** — Validates `restaurant_id` (required, exists, must be active), `workflow_type` (required, in enum), `restaurant_rate` (required, numeric, min:0, max:9999999999.99).
- **UpdateShiftRequest** — Validates `restaurant_rate`, `workflow_type` (with BR-01 lock check), allows same-value resubmission.
- **CloseShiftRequest** — Validates shift status must be `open` before closing.

### Route Design

Resource routes (`Route::resource('shifts', ...)`) plus custom `POST /shifts/{shift}/close` action. All under `auth` + `role:admin` middleware group.

### Blade Views with PT-BR Localization

Four views using a shared `layouts/app.blade.php` layout: index (list with status filter), create, edit, show. Status and workflow_type labels displayed in Portuguese.

### Controller-Side `created_by`

The `created_by` field is set server-side in the controller from `$request->user()->id`, never from form input. This prevents tampering.

### Paginated Index with Status Filter

Index action supports `?status=draft|open|closed|approved|paid` query param filtering with whitelist validation. Paginated at 15 per page, ordered by `created_at DESC`.

---

## Alternatives Considered

| # | Alternative | Pros | Cons | Why Rejected |
|---|-------------|------|------|--------------|
| 1 | **Single Request class for store/update** | Less code duplication | Different validation rules (store requires restaurant_id, update needs BR-01 check); merging increases complexity | Separate requests are clearer and safer |
| 2 | **API (JSON) endpoints instead of web** | SPA-friendly, easier mobile integration | PRD specifies Blade + Vite; no API requirement in this phase | Web routes match architecture decision (Blade + Vite) |
| 3 | **Separate "open" action** (draft→open transition) | Explicit state transition endpoint | The update action can handle status changes on draft shifts; adding a dedicated endpoint is premature | Can be added later when shift opening workflow is defined |
| 4 | **Soft deletes on shifts** | Recoverable deletions | Not in requirements; adds query complexity; shifts are operational data that should be archived, not deleted | Out of scope for MVP |
| 5 | **`decimal:0,2` validation rule** (plan specified) | Enforces exactly 2 decimal places | Too strict — rejects valid inputs like `15` or `15.5` that the DECIMAL column handles correctly | `numeric` is more user-friendly and plan's own edge cases support it |

---

## Consequences

### Positive

- **Complete Admin shift lifecycle** — Create, list, view, edit, close — all wired and tested.
- **BR-01 enforced at 3 layers** — Defense-in-depth prevents workflow_type mutation via any vector.
- **BR-05 enforced at 2 layers** — Route middleware + Policy ensure admin-only access.
- **No schema changes** — Reuses existing Phase 1 schema entirely.
- **74 thorough tests** — 47 ACs + additional boundary/security cases, all passing.
- **PT-BR UI** — Consistent Portuguese interface for admin users.
- **Form Request validation** — Dedicated classes keep controller logic clean and testable.

### Negative

- **Dashboard navigation gap (M-01)** — Plan specified adding "Turnos" link to `dashboard.blade.php`; only added to `layouts/app.blade.php` nav. Admin must manually navigate to shifts from the dashboard.
- **`numeric` vs `decimal:0,2` (M-02)** — Slightly more permissive validation than plan specified, though consistent with plan's edge case intent.
- **English text in PT-BR UI (L-01)** — "No shifts found" should be "Nenhum turno encontrado."
- **Inline Blade query (L-02)** — `User::find($shift->created_by)` in show view instead of eager-loaded relationship.

### Risks

- **State transition gaps** — No dedicated draft→open action yet; relies on update mechanism. May need explicit endpoint in future phases.
- **No shift deletion** — Destroy action explicitly out of scope. Draft shifts accumulate until archived.
- **Single-role admin assumption** — All shift operations require admin role. If Restaurant Managers need limited shift access, middleware refactoring needed.

---

## Artefacts Affected

| Type | File | Change |
|------|------|--------|
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | Created |
| Request | `app/Http/Requests/StoreShiftRequest.php` | Created |
| Request | `app/Http/Requests/UpdateShiftRequest.php` | Created |
| Request | `app/Http/Requests/CloseShiftRequest.php` | Created |
| Routes | `routes/web.php` | Modified (admin-only resource + close route) |
| Policy | `app/Policies/ShiftPolicy.php` | Modified (added `close` method) |
| View | `resources/views/layouts/app.blade.php` | Created |
| View | `resources/views/shifts/index.blade.php` | Created |
| View | `resources/views/shifts/create.blade.php` | Created |
| View | `resources/views/shifts/edit.blade.php` | Created |
| View | `resources/views/shifts/show.blade.php` | Created |
| Test | `tests/Feature/Controllers/ShiftControllerTest.php` | Created (74 tests) |

---

## Acceptance Criteria Covered

All 47 acceptance criteria (AC-2B-01 through AC-2B-47) covering:
- Shift creation / store (AC-2B-01 to AC-2B-11)
- Shift listing / index (AC-2B-12 to AC-2B-19)
- Shift detail / show (AC-2B-20 to AC-2B-24)
- Shift edit / update (AC-2B-25 to AC-2B-31)
- Shift close (AC-2B-32 to AC-2B-37)
- Authorization & security (AC-2B-38 to AC-2B-41)
- Regression (AC-2B-42 to AC-2B-43)
- Views / smoke tests (AC-2B-44 to AC-2B-47)

---

## References

- Plan: `docs/plans/phase-2b-shift-crud-lifecycle.md`
- Audit: `docs/audits/phase-2b-shift-crud-lifecycle-audit.md`
- PRD Section 2C: Company Manager — manages contracts, reviews shifts, controls lifecycle
- PRD Section 3: Rate & Revenue Management
- PRD Section 4: BR-01 — Workflow Locking
- Tech Doc Section 3: Business Logic & Formulas
- Tech Doc Section 5: Security & Guardrails — BR-01, BR-05
- ADR-001: Core Payout Schema (Shift state machine, BR-01)
- ADR-002: Auth & Roles (RBAC, middleware, policies)

---

_See [ADR Index](./README.md) for all decisions._
