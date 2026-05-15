# Plan: Phase 2C — Shift-Biker Assignment

**Task ID:** Phase-2C
**Date:** 2026-05-14
**Planner Version:** 1.0
**Complexity:** Medium

---

## 1. Objective

Implement admin management of biker assignments to shifts. This provides the `ShiftBikerController` (admin-only) with full CRUD over the `shift_bikers` pivot table, enabling the Admin to assign, update, and remove bikers from shifts. The feature enforces **BR-05** (only Admin can add/remove bikers from shifts) and validates that bikers can only be assigned to shifts in `draft` or `open` status, with no duplicate assignments. All 407 existing tests must continue to pass.

---

## 2. Source References

### User Stories
- No direct US-XX; this is an infrastructure capability required by the overall shift lifecycle. It underpins US-01 (Trip Sheet needs bikers assigned) and supports the Admin persona's "Registry" flow.

### Business Rules
- **BR-05: Last Minute Biker** — Only the Admin can add/replace bikers once a shift has been initiated. This is the primary business rule for Phase 2C.
- **BR-01: Workflow Locking** — Assignment is constrained by shift status; only `draft` and `open` shifts accept biker changes.

### PRD Sections
- Section 2C (Company Manager / Admin): "Registry: Manages Restaurant contracts (Rates) and Biker data (PIX)."
- Section 4 (BR-05): "Only the Admin can add/replace bikers once a shift has been initiated."

### Tech Doc Sections
- Section 3 (Business Logic & Formulas): ShiftBiker stores `biker_rate` and `base_fee` per assignment.
- Section 5 (Security & Guardrails): BR-05 enforcement.

---

## 3. Scope

### In Scope
1. `ShiftBikerController` (Admin namespace) with `index`, `store`, `update`, `destroy` actions
2. `AssignBikerRequest` form request — validates biker assignment (store)
3. `UpdateShiftBikerRequest` form request — validates biker detail updates (update)
4. Web routes nested under shifts (`shifts/{shift}/bikers`) — admin-only, protected by `auth` + `role:admin` middleware
5. Blade partials for biker assignment management rendered within the shift detail page (`shifts.show`)
6. Validation rules: shift must be `draft` or `open`, no duplicate biker, biker must exist and be active
7. Controller-level authorization via `ShiftPolicy@addBiker` and the `manage-shift-bikers` gate
8. Feature tests covering all acceptance criteria

### Out of Scope
1. Live tick / trip count incrementing (future phase)
2. Payment creation or payout calculation at assignment time
3. Biker self-registration or biker-facing UI
4. PIX key management integration
5. API routes (JSON) — web/Blade only
6. Bulk biker assignment
7. Changing shift status during assignment (opening/closing shifts)
8. Any modification to the `Shift` model's saving hook or state machine

### Open Questions
1. **Should inactive bikers be assignable?** — Assumption: No. Only `active = true` bikers can be assigned. If this is incorrect, the Developer should remove the `active` check in `AssignBikerRequest`.
2. **Should the `store` action pre-populate `biker_rate` and `base_fee` from the Biker model defaults?** — Assumption: Yes. The form sends values, but they default to the biker's `rate_per_trip` and `base_fee` if not provided. The Developer should merge defaults in the controller if the fields are omitted.
3. **What happens to `ShiftBiker` records when a shift transitions to `closed`?** — Assumption: Records are preserved (no cascade). Bikers cannot be added/removed from closed+ shifts, but existing records remain for payout calculations. No action needed in this phase.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | Yes | Biker assignment only allowed on `draft` or `open` shifts. Assignment on `closed`/`approved`/`paid` shifts returns validation error. |
| BR-02 PIX Verification | No | Not relevant to assignment. |
| BR-03 Manual Release | No | Not relevant to assignment. Payout calculation is deferred. |
| BR-04 Granular Failure | No | Not relevant to assignment. |
| BR-05 Last Minute Biker | **Yes** | All shift-biker routes are protected by `role:admin` middleware. Controller additionally authorizes via `ShiftPolicy@addBiker`. The `manage-shift-bikers` gate exists and is already registered. |
| BR-06 Payment Retries | No | Not relevant to assignment. |

---

## 5. Schema Changes

### New Tables

No new tables. The `shift_bikers` table already exists (`2026_05_14_000004_create_shift_bikers_table.php`) with all required columns.

### Modified Tables

No modifications.

### Indexes

No new indexes. The existing `unique(['shift_id', 'biker_id'])` on `shift_bikers` already enforces the no-duplicate constraint at the database level.

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| biker_rate | shift_bikers | DECIMAL(12,2) | Yes (stored, not calculated in this phase) |
| base_fee | shift_bikers | DECIMAL(12,2) | Yes (stored, not calculated in this phase) |

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Controller | `app/Http/Controllers/Admin/ShiftBikerController.php` | Admin CRUD for shift-biker assignments (index, store, update, destroy) |
| Request | `app/Http/Requests/AssignBikerRequest.php` | Validates biker_id + financial fields for store action |
| Request | `app/Http/Requests/UpdateShiftBikerRequest.php` | Validates biker_rate/base_fee/trips_count for update action |
| View (partial) | `resources/views/shifts/partials/biker-assignments.blade.php` | Reusable partial rendering the biker list + assignment form |
| Test | `tests/Feature/Controllers/ShiftBikerControllerTest.php` | Feature tests for all shift-biker assignment acceptance criteria |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Route | `routes/web.php` | Add nested resource routes `shifts/{shift}/bikers` under the existing admin middleware group |
| View | `resources/views/shifts/show.blade.php` | Include the new `biker-assignments` partial; load `shiftBikers` relationship on the shift eager-loaded in `ShiftController@show` |
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | In the `show()` method, eager-load `shiftBikers.biker` relationship: `$shift->load('restaurant', 'shiftBikers.biker')` |

---

## 7. Pseudocode

### Critical Business Logic

#### AssignBikerRequest Validation (store)

```
RULES:
  biker_id:
    - required
    - integer
    - exists:bikers,id
    - custom closure: biker must be active (bikers.active = true)
    - custom closure: biker must not already be assigned to this shift
        → ShiftBiker::where('shift_id', route('shift')->id)
                      ->where('biker_id', value)->exists() → fail with "Este entregador já está atribuído a este turno."
  biker_rate:
    - required
    - numeric
    - min:0
    - max:9999999999.99
  base_fee:
    - required
    - numeric
    - min:0
    - max:9999999999.99

WITH_VALIDATOR:
  after validation:
    shift = route('shift')
    if shift.status NOT IN [Draft, Open]:
      add error: "Bikers can only be assigned to draft or open shifts."
```

#### UpdateShiftBikerRequest Validation (update)

```
RULES:
  biker_rate:
    - sometimes
    - required
    - numeric
    - min:0
    - max:9999999999.99
  base_fee:
    - sometimes
    - required
    - numeric
    - min:0
    - max:9999999999.99
  trips_count:
    - sometimes
    - required
    - integer
    - min:0

WITH_VALIDATOR:
  after validation:
    shift = route('shift')
    if shift.status NOT IN [Draft, Open]:
      add error: "Biker details can only be updated on draft or open shifts."
```

#### ShiftBikerController@store

```
FUNCTION store(AssignBikerRequest request, Shift shift):
  authorize('addBiker', shift)   // ShiftPolicy → admin only

  validated = request.validated()

  // If biker_rate/base_fee not provided, fill from Biker model defaults
  biker = Biker::find(validated['biker_id'])
  validated['biker_rate'] ??= biker->rate_per_trip
  validated['base_fee'] ??= biker->base_fee
  validated['trips_count'] = 0   // New assignment always starts at 0

  shiftBiker = shift->shiftBikers()->create(validated)

  RETURN redirect()->route('shifts.show', shift)
    ->with('success', 'Entregador atribuído com sucesso.')
```

#### ShiftBikerController@update

```
FUNCTION update(UpdateShiftBikerRequest request, Shift shift, ShiftBiker $biker):
  // Verify the shift_biker belongs to this shift
  if biker.shift_id !== shift.id:
    ABORT 404

  // Verify shift is still mutable (defense-in-depth; request already validates)
  if shift.status NOT IN [Draft, Open]:
    ABORT 403

  biker.update(request.validated())

  RETURN redirect()->route('shifts.show', shift)
    ->with('success', 'Dados do entregador atualizados.')
```

#### ShiftBikerController@destroy

```
FUNCTION destroy(Request request, Shift shift, ShiftBiker $biker):
  authorize('addBiker', shift)   // BR-05: same gate — only admin

  // Verify the shift_biker belongs to this shift
  if biker.shift_id !== shift.id:
    ABORT 404

  // Verify shift is still mutable
  if shift.status NOT IN [Draft, Open]:
    RETURN back()->with('error', 'Não é possível remover entregadores de um turno encerrado.')

  biker.delete()

  RETURN redirect()->route('shifts.show', shift)
    ->with('success', 'Entregador removido do turno.')
```

#### ShiftBikerController@index

```
FUNCTION index(Request request, Shift shift):
  authorize('view', shift)   // ShiftPolicy → admin always, restaurant_manager if owns it

  shiftBikers = shift->shiftBikers()->with('biker')->get()

  // Return JSON or partial — since this is embedded in show view,
  // index is primarily for the partial. Can also serve as a standalone page.

  RETURN view('shifts.partials.biker-assignments', compact('shift', 'shiftBikers'))
```

### State Transitions

```
[Draft Shift] ──(assign biker)──▶ [Draft Shift + Biker]
       │
       └──(open shift)──▶ [Open Shift + Biker]
                              │
            ┌─────────────────┤
            │                 │
   (assign more bikers)  (remove biker)
            │                 │
            ▼                 ▼
   [Open Shift + Bikers]  [Open Shift - Biker]
            │
            └──(close)──▶ [Closed Shift — bikers frozen]
                              │
                              └── NO add/remove allowed
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware | Route Name |
|--------|-----|-------------------|------|------------|------------|
| GET | `/shifts/{shift}/bikers` | `ShiftBikerController@index` | Admin | `auth`, `role:admin` | `shifts.bikers.index` |
| POST | `/shifts/{shift}/bikers` | `ShiftBikerController@store` | Admin | `auth`, `role:admin` | `shifts.bikers.store` |
| PUT/PATCH | `/shifts/{shift}/bikers/{biker}` | `ShiftBikerController@update` | Admin | `auth`, `role:admin` | `shifts.bikers.update` |
| DELETE | `/shifts/{shift}/bikers/{biker}` | `ShiftBikerController@destroy` | Admin | `auth`, `role:admin` | `shifts.bikers.destroy` |

**Note:** The `{biker}` route parameter resolves to `ShiftBiker` model (not `Biker`). Use explicit route key binding or resolve via `ShiftBiker` where the scoped query ensures `shift_id` match.

---

## 8. Edge Cases

1. **Duplicate biker assignment** — Assigning the same biker to the same shift twice must fail with validation error, even if the form resubmits. The database `UNIQUE(shift_id, biker_id)` constraint acts as a safety net; the form request should catch it first with a clear message.
2. **Assigning to a closed/approved/paid shift** — Must return validation error. Both the form request and the controller should guard against this (defense-in-depth).
3. **Removing a biker from a closed shift** — Must fail with error flash message. The biker record must remain for payout calculations.
4. **Non-existent biker ID** — Must return validation error via `exists:bikers,id`.
5. **Inactive biker** — Must return validation error; inactive bikers cannot be assigned.
6. **Non-existent ShiftBiker on update/destroy** — Must return 404. The route model binding should resolve `{biker}` as a `ShiftBiker` scoped to the shift.
7. **ShiftBiker belongs to a different shift** — If the `{biker}` route param resolves to a `ShiftBiker` from a different shift, return 404.
8. **Negative biker_rate or base_fee** — Must return validation error (`min:0`).
9. **Zero trips_count on update** — Must be allowed (resetting trip count to 0 is valid).
10. **Concurrent requests** — Two admins assigning the same biker to the same shift simultaneously. The DB unique constraint catches this; the controller should handle the query exception gracefully with a flash message.
11. **Admin authorizing via ShiftPolicy** — Even though middleware enforces `role:admin`, the controller must also call `$this->authorize('addBiker', $shift)` for defense-in-depth. This reuses the existing `ShiftPolicy@addBiker` method and `manage-shift-bikers` gate.
12. **Empty biker list on shift show** — When no bikers are assigned, the partial should show "Nenhum entregador atribuído" and the assignment form (if shift is draft/open).

---

## 9. Acceptance Criteria

These are the **exact conditions** the Tester will verify. Each must be atomic and unambiguous.

### Route & Authorization

- [ ] AC-2C-01: `GET /shifts/{shift}/bikers` returns 200 for admin user
- [ ] AC-2C-02: `POST /shifts/{shift}/bikers` (store) returns redirect for admin user on valid data
- [ ] AC-2C-03: `PATCH /shifts/{shift}/bikers/{biker}` (update) returns redirect for admin user on valid data
- [ ] AC-2C-04: `DELETE /shifts/{shift}/bikers/{biker}` (destroy) returns redirect for admin user
- [ ] AC-2C-05: All shift-biker routes require authentication (unauthenticated → redirect to login)
- [ ] AC-2C-06: Non-admin user (RestaurantManager) receives 403 on all shift-biker routes
- [ ] AC-2C-07: Non-admin user (Biker) receives 403 on all shift-biker routes

### Store (Assign Biker)

- [ ] AC-2C-08: Admin can assign an active biker to a draft shift → ShiftBiker record created
- [ ] AC-2C-09: Admin can assign an active biker to an open shift → ShiftBiker record created
- [ ] AC-2C-10: Assigning creates ShiftBiker with `trips_count = 0`, `biker_rate` and `base_fee` from form input
- [ ] AC-2C-11: If `biker_rate` is omitted, it defaults to the Biker's `rate_per_trip`
- [ ] AC-2C-12: If `base_fee` is omitted, it defaults to the Biker's `base_fee`
- [ ] AC-2C-13: After successful assignment, redirected to `shifts.show` with success flash
- [ ] AC-2C-14: Assigning a biker to a closed shift returns validation error
- [ ] AC-2C-15: Assigning a biker to an approved shift returns validation error
- [ ] AC-2C-16: Assigning a biker to a paid shift returns validation error
- [ ] AC-2C-17: Assigning the same biker twice to the same shift returns validation error
- [ ] AC-2C-18: Assigning a non-existent biker_id returns validation error
- [ ] AC-2C-19: Assigning an inactive biker returns validation error
- [ ] AC-2C-20: Missing `biker_id` returns validation error
- [ ] AC-2C-21: Negative `biker_rate` returns validation error
- [ ] AC-2C-22: Negative `base_fee` returns validation error

### Update (Modify Biker Details)

- [ ] AC-2C-23: Admin can update `biker_rate` on a ShiftBiker in a draft shift
- [ ] AC-2C-24: Admin can update `biker_rate` on a ShiftBiker in an open shift
- [ ] AC-2C-25: Admin can update `base_fee` on a ShiftBiker
- [ ] AC-2C-26: Admin can update `trips_count` on a ShiftBiker (manual entry workflow)
- [ ] AC-2C-27: After successful update, redirected to `shifts.show` with success flash
- [ ] AC-2C-28: Updating a ShiftBiker on a closed shift returns validation error
- [ ] AC-2C-29: Updating a ShiftBiker on an approved shift returns validation error
- [ ] AC-2C-30: Negative `trips_count` returns validation error
- [ ] AC-2C-31: Updating a ShiftBiker that belongs to a different shift returns 404

### Destroy (Remove Biker)

- [ ] AC-2C-32: Admin can remove a biker from a draft shift → ShiftBiker deleted
- [ ] AC-2C-33: Admin can remove a biker from an open shift → ShiftBiker deleted
- [ ] AC-2C-34: After successful removal, redirected to `shifts.show` with success flash
- [ ] AC-2C-35: Removing a biker from a closed shift returns error flash
- [ ] AC-2C-36: Removing a biker from an approved shift returns error flash
- [ ] AC-2C-37: Removing a non-existent ShiftBiker returns 404
- [ ] AC-2C-38: Removing a ShiftBiker from a different shift returns 404

### Views

- [ ] AC-2C-39: The shift show page (`shifts.show`) displays the list of assigned bikers
- [ ] AC-2C-40: The biker list shows biker name, biker_rate, base_fee, and trips_count
- [ ] AC-2C-41: When no bikers are assigned, "Nenhum entregador atribuído" message is displayed
- [ ] AC-2C-42: An "Assign Biker" form is visible when the shift is in draft or open status
- [ ] AC-2C-43: The "Assign Biker" form is hidden when the shift is closed, approved, or paid
- [ ] AC-2C-44: Each assigned biker row has a "Remove" button when shift is draft or open
- [ ] AC-2C-45: The "Remove" button is hidden when the shift is closed, approved, or paid
- [ ] AC-2C-46: Each assigned biker row has an "Edit" button for inline or modal editing (draft/open only)

### Regression

- [ ] AC-2C-47: All 407 existing tests continue to pass (no regressions)

---

## 10. Security Considerations

- **Authorization:** All routes protected by `auth` + `role:admin` middleware. Controller additionally calls `$this->authorize('addBiker', $shift)` for store/destroy, reusing `ShiftPolicy@addBiker`. The existing `manage-shift-bikers` gate is already registered in `AppServiceProvider`.
- **Input Validation:** `AssignBikerRequest` validates `biker_id` (exists + active + not duplicate), `biker_rate` (numeric, min:0, max), `base_fee` (numeric, min:0, max). `UpdateShiftBikerRequest` validates `biker_rate`, `base_fee`, `trips_count`. Both check shift status is draft or open.
- **Route Model Binding Security:** The `{biker}` parameter resolves to `ShiftBiker` (not `Biker`). The controller must verify `$shiftBiker->shift_id === $shift->id` to prevent cross-shift manipulation. Alternative: use scoped route binding.
- **Container Compliance:** All operations run within `/workspaces/bikerflow`. No external API calls. No filesystem access outside the project root.
- **Financial Safety:** `biker_rate` and `base_fee` are validated as numeric with min:0. Database columns are `DECIMAL(12,2)`. Model casts use `decimal:2`. No calculations in this phase (BCMath not needed in controller), but the stored values will be consumed by `PayoutService` which uses BCMath.
- **CSRF Protection:** All state-changing routes (POST, PUT/PATCH, DELETE) use Blade `@csrf` tokens, enforced by Laravel's `VerifyCsrfToken` middleware.
- **Mass Assignment:** `ShiftBiker.$fillable` already includes `shift_id`, `biker_id`, `trips_count`, `biker_rate`, `base_fee`. No changes needed.
