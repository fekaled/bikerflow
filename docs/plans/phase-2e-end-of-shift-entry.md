# Plan: Phase 2E — End-of-Shift Entry (Manual Trip Count)

**Task ID:** Phase-2E
**Date:** 2026-05-15
**Planner Version:** 1.0
**Complexity:** Medium

---

## 1. Objective

Implement manual trip count entry for Restaurant Managers at shift close. When a shift's `workflow_type` is `manual_entry`, the Restaurant Manager accesses an entry form showing all assigned bikers, enters final trip totals, and submits them. Upon submission, each `ShiftBiker`'s `trips_count` is updated and the shift optionally transitions to `closed`. This is the complementary tracking workflow to Phase 2D's Live Tick — together they fulfil the PRD §2A Restaurant Manager persona requirement.

---

## 2. Source References

### User Stories
- PRD §2A — Restaurant Manager persona: "At shift start, chooses between 'Live Tick' (real-time + button) or **'End-of-Shift Entry'** (manual total entry)."

### Business Rules
- **BR-01** — Workflow Locking: Entry endpoint must reject requests if shift `workflow_type` is not `manual_entry`. The tracking method is locked at shift creation and cannot be changed.
- **BR-05** — Last Minute Biker: Only Admin can add/replace bikers. Restaurant Managers can only submit trips for already-assigned bikers.

### PRD Sections
- §2A — Restaurant Manager workflow (chooses Live Tick or End-of-Shift Entry)
- §2C — Restaurant Manager closes shift and sees total owed
- §4 — BR-01 (Workflow Locking), BR-05 (Last Minute Biker)

### Tech Doc Sections
- §3 — Business Logic & Formulas (context — payout calc deferred to Phase 3)
- §5 — Security & Guardrails (BR-01 enforcement)

---

## 3. Scope

### In Scope
1. `ShiftEntryController` (RestaurantManager namespace) with two actions: `show` (display entry form) and `store` (submit final trip totals)
2. `SubmitTripsRequest` form request validating all preconditions (shift open, workflow is `manual_entry`, each biker is assigned, trips are non-negative integers, user is the Restaurant Manager for this shift's restaurant)
3. Web routes for manual entry, protected by `auth` + `role:restaurant_manager` middleware
4. Blade view for the manual entry form showing assigned bikers with trip count input fields
5. BR-01 enforcement: entry endpoint rejects if `workflow_type` is not `manual_entry`
6. Upon submission, update each `ShiftBiker`'s `trips_count` and optionally transition shift to `closed`
7. ShiftPolicy update: add `submitTrips(User, Shift): bool` method
8. Navigation integration — the tracking dashboard detects `manual_entry` shifts and links to the entry form

### Out of Scope
1. Payout calculation on submission — deferred to Phase 3
2. Shift creation/opening by Restaurant Managers — Admin-only
3. Biker assignment/removal by Restaurant Managers — BR-05, Admin-only
4. Live Tick tracking — already implemented in Phase 2D
5. Payment/PIX integration — future phases
6. AJAX/API JSON endpoints — form POST with redirect is sufficient for MVP
7. Shift closing as a separate action — this phase handles optional close as part of trip submission

### Open Questions
1. **Should submitting trips automatically close the shift, or should closing remain a separate Admin-only step?** — Assumption: Submission updates `trips_count` on all `ShiftBiker` records and **optionally** transitions the shift to `closed`. The form includes a checkbox "Encerrar turno" (Close shift). If checked, the shift status transitions to `closed` and `closed_at` is set. This mirrors the PRD §2A flow: "At shift close, sees the total amount owed." If the PRD intends closing to be Admin-only (like Phase 2B), then the checkbox should be omitted and closing remains Admin-only. **Flagging as Open Question #1.**
2. **Should Admin users also be able to submit manual entries?** — Assumption: Yes, by analogy with Phase 2D where Admin can tick any shift. Admin has full access. This is consistent with the existing role middleware pattern (`role:restaurant_manager,admin`).
3. **What if a shift has no assigned bikers?** — Assumption: The form still renders but shows a message "Nenhum entregador atribuído" (No bikers assigned). Submission with zero bikers is valid (no-op) but probably should still transition the shift if requested.
4. **Can trips be submitted multiple times (re-submission)?** — Assumption: Yes, as long as the shift is still `open`. The `trips_count` values are overwritten (not incremented). This is consistent with the "manual entry" concept — the RM is entering the final totals. Re-submission before close is an intentional edit flow.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | **Yes** | `SubmitTripsRequest` validates `shift.workflow_type === WorkflowType::ManualEntry`. Reject with validation error if `live_tick` or any other value. |
| BR-02 PIX Verification | No | Not relevant to trip entry. |
| BR-03 Manual Release | **Yes (context)** | No payments triggered on submission. Payout calc is Phase 3. |
| BR-04 Granular Failure | No | No payments in this phase. |
| BR-05 Last Minute Biker | **Yes (context)** | Restaurant Managers cannot add/remove bikers. They can only submit trips for already-assigned bikers. Existing policy/middleware already enforces this. |
| BR-06 Payment Retries | No | No payments in this phase. |

---

## 5. Schema Changes

### New Tables

No new tables.

### Modified Tables

No modifications.

### Indexes

No new indexes.

### Financial Column Checklist

N/A — no new financial columns. The entry endpoint updates `shift_bikers.trips_count` (UNSIGNED INTEGER), not a monetary column.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Controller | `app/Http/Controllers/RestaurantManager/ShiftEntryController.php` | `show()` and `store()` actions for manual trip entry |
| Request | `app/Http/Requests/SubmitTripsRequest.php` | Form request validating trip submission preconditions (BR-01, shift open, bikers assigned, auth) |
| View | `resources/views/entry/show.blade.php` | Manual trip entry form — biker list with trip count input fields |
| Test | `tests/Feature/Controllers/ShiftEntryControllerTest.php` | Feature tests for all acceptance criteria |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Policy | `app/Policies/ShiftPolicy.php` | Add `submitTrips(User, Shift): bool` method — Restaurant Manager can submit trips for their own restaurant's open manual_entry shifts; Admin can submit trips for any shift |
| Route | `routes/web.php` | Add new route group: `middleware(['auth', 'role:restaurant_manager,admin'])` with `GET /entry/{shift}` (show form) and `POST /entry/{shift}` (submit trips) |
| View | `resources/views/tracking/dashboard.blade.php` | Update manual_entry shift display: replace "Contagem manual" text with a link/button to `entry.show` route |
| View | `resources/views/layouts/app.blade.php` | Optionally add "Entrada Manual" nav link for Restaurant Managers (or reuse "Acompanhamento" which already shows both workflow types) |

---

## 7. Pseudocode

### Critical Business Logic — Store Action

```
CONTROLLER ShiftEntryController@show(request, shift):
    // Authorization validated by SubmitTripsRequest + Policy
    
    // Load shift with assigned bikers
    shift->load('shiftBikers.biker', 'restaurant')
    
    RETURN view('entry.show', compact('shift'))

CONTROLLER ShiftEntryController@store(request, shift):
    // Authorization + validation already done by SubmitTripsRequest
    
    validatedData = request.validated()
    bikers = validatedData['bikers']  // Array of {biker_id, trips_count}
    
    // Update each ShiftBiker's trips_count
    FOR EACH entry IN bikers:
        shiftBiker = ShiftBiker::where('shift_id', shift.id)
                               ->where('biker_id', entry['biker_id'])
                               ->first()
        
        // Defense-in-depth: should never be null due to validation
        IF shiftBiker IS NOT NULL:
            shiftBiker->trips_count = entry['trips_count']
            shiftBiker->save()
    
    // Optionally close the shift if requested
    IF validatedData['close_shift'] IS TRUE:
        shift->status = ShiftStatus::Closed
        shift->closed_at = now()
        shift->save()
    
    REDIRECT to tracking.dashboard WITH success flash "Viagens registradas com sucesso!"
```

### SubmitTripsRequest Validation Logic

```
CLASS SubmitTripsRequest EXTENDS FormRequest:
    
    authorize():
        user = this.user()
        shift = this.route('shift')
        
        // Admin can submit trips for any shift
        IF user.isAdmin():
            RETURN true
        
        // Restaurant Manager can only submit for their own restaurant's shift
        IF user.isRestaurantManager():
            RETURN shift.restaurant_id === user.restaurant_id
        
        RETURN false
    
    rules():
        RETURN {
            'bikers': ['required', 'array', 'min:1'],
            'bikers.*.biker_id': ['required', 'integer', 'exists:bikers,id'],
            'bikers.*.trips_count': ['required', 'integer', 'min:0'],
            'close_shift': ['sometimes', 'boolean'],
        }
    
    withValidator(validator):
        validator.after(FUNCTION (validator):
            shift = this.route('shift')
            
            // Rule 1: Shift must be open
            IF shift.status !== ShiftStatus::Open:
                validator.errors.add('shift', 'Somente turnos abertos podem receber entradas.')
                RETURN   // Skip further checks
            
            // Rule 2 (BR-01): Shift workflow must be manual_entry
            IF shift.workflow_type !== WorkflowType::ManualEntry:
                validator.errors.add('workflow_type', 'Este turno não usa entrada manual.')
                RETURN
            
            // Rule 3: Every biker in the request must be assigned to this shift
            bikers = this.input('bikers', [])
            FOR EACH entry IN bikers:
                bikerId = entry['biker_id'] ?? null
                IF bikerId IS NOT NULL:
                    isAssigned = ShiftBiker::where('shift_id', shift.id)
                                            ->where('biker_id', bikerId)
                                            ->exists()
                    IF NOT isAssigned:
                        validator.errors.add(
                            'bikers.' + index + '.biker_id',
                            'Entregador não está atribuído a este turno.'
                        )
            
            // Rule 4: All assigned bikers must be present in the submission
            // (prevent partial submissions — RM must enter counts for ALL bikers)
            assignedBikerIds = ShiftBiker::where('shift_id', shift.id)
                                         ->pluck('biker_id')
                                         ->toArray()
            submittedBikerIds = collect(bikers)->pluck('biker_id')->toArray()
            missingIds = array_diff(assignedBikerIds, submittedBikerIds)
            IF count(missingIds) > 0:
                validator.errors.add(
                    'bikers',
                    'Todos os entregadores atribuídos devem ter suas viagens registradas.'
                )
        )
```

### ShiftPolicy@submitTrips

```
POLICY ShiftPolicy@submitTrips(user, shift):
    IF user.isAdmin():
        RETURN true
    
    IF user.isRestaurantManager():
        RETURN shift.status === ShiftStatus::Open
               AND shift.restaurant_id === user.restaurant_id
    
    RETURN false
```

### State Transitions

```
Shift: [draft] --(Admin opens)--> [open] ──────────────────────────────────────> [closed] --> ...
                                        │                                    ▲
                                        │ Restaurant Manager can SUBMIT       │
                                        │ manual trips here                   │
                                        │ (optional close_shift checkbox)     │
                                        │                                    │
                                        └── BR-01 guard: only if workflow_type = manual_entry
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware | Name |
|--------|-----|-------------------|------|------------|------|
| GET | `/entry/{shift}` | `ShiftEntryController@show` | Restaurant Manager or Admin | `auth`, `role:restaurant_manager,admin` | `entry.show` |
| POST | `/entry/{shift}` | `ShiftEntryController@store` | Restaurant Manager or Admin | `auth`, `role:restaurant_manager,admin` | `entry.store` |

**Middleware note:** Reuses the existing `EnsureUserRole` middleware with `role:restaurant_manager,admin`, consistent with Phase 2D's tracking routes. Admin access is permitted for observation and override.

### Tracking Dashboard Integration

The existing `tracking/dashboard.blade.php` already shows `manual_entry` shifts with the text "Contagem manual". Update this section to show a link/button instead:

```
@if($shift->workflow_type->value === 'manual_entry')
    <a href="{{ route('entry.show', $shift) }}"
       class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
        Registrar Viagens
    </a>
@else
    <!-- existing tick button -->
@endif
```

### Entry Form Layout (Blade View)

```
@extends('layouts.app')

@section('title', 'Registrar Viagens')

@section('content')
    <h1>Turno #{{ shift->id }} — {{ shift->restaurant->name }}</h1>
    <p>Workflow: Entrada Manual | Status: Aberto</p>
    
    <form method="POST" action="{{ route('entry.store', shift) }}">
        @csrf
        
        <table>
            <thead>
                <tr>
                    <th>Entregador</th>
                    <th>Viagens</th>
                </tr>
            </thead>
            <tbody>
                @foreach(shift->shiftBikers as shiftBiker)
                    <tr>
                        <td>{{ shiftBiker->biker->name }}</td>
                        <td>
                            <input type="number"
                                   name="bikers[{{ $loop->index }}][trips_count]"
                                   value="{{ shiftBiker->trips_count }}"
                                   min="0"
                                   required />
                            <input type="hidden"
                                   name="bikers[{{ $loop->index }}][biker_id]"
                                   value="{{ shiftBiker->biker_id }}" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        
        <label>
            <input type="checkbox" name="close_shift" value="1" />
            Encerrar turno após registrar
        </label>
        
        <button type="submit">Registrar Viagens</button>
    </form>
@endsection
```

---

## 8. Edge Cases

1. **Shift not open** — `SubmitTripsRequest` must reject with validation error. Trip counts must NOT change.
2. **Shift workflow is `live_tick`** — BR-01: `SubmitTripsRequest` must reject. This is the core BR-01 enforcement for the manual entry side.
3. **Biker not assigned to shift** — `SubmitTripsRequest` must reject any `biker_id` that has no `ShiftBiker` record.
4. **Restaurant Manager submitting for another restaurant's shift** — `SubmitTripsRequest@authorize()` must return false → 403.
5. **Restaurant Manager with null `restaurant_id`** — If user has role `restaurant_manager` but `restaurant_id` is null, show form returns 403 and store is denied. Must not crash.
6. **Partial submission (not all assigned bikers included)** — Validation error. All assigned bikers must have trip counts entered.
7. **Empty `bikers` array** — Validation error (`min:1` rule).
8. **Negative `trips_count`** — Validation error (`min:0` rule).
9. **Non-integer `trips_count`** — Validation error (`integer` rule).
10. **Shift that was just closed** — Race condition: shift is open when page loads but closed when submission arrives. `SubmitTripsRequest` validates against fresh shift state → validation error.
11. **Non-existent shift ID in URL** — Route model binding returns 404 automatically.
12. **Shift with no assigned bikers** — Form renders with empty biker list. Submission with zero bikers fails validation (`min:1`). Admin should assign bikers first.
13. **Re-submission on already-submitted shift** — If shift is still open, re-submission is allowed. Values are overwritten (not cumulative). This is by design for manual entry.
14. **Unauthenticated access** — `auth` middleware redirects to login.
15. **Biker user trying to access entry form** — `role:restaurant_manager,admin` middleware returns 403.
16. **Admin submitting for any restaurant's shift** — Allowed. Admin has full access.
17. **`close_shift` checkbox checked but shift transition fails** — The Shift model's `saving` hook enforces state transitions. If the transition from `open` to `closed` is invalid (should not happen per current code), catch the exception and return error.
18. **Very large `trips_count` value** — `integer` rule allows any valid integer. The database column is `UNSIGNED INTEGER` (max 4,294,967,295). Practical maximum is far below this. No additional validation needed.

---

## 9. Acceptance Criteria

These are the **exact conditions** the Tester will verify. Each must be atomic and unambiguous.

### Route & Middleware (AC-2E-01 through AC-2E-04)

- [ ] **AC-2E-01:** `GET /entry/{shift}` returns 200 for an authenticated Restaurant Manager when the shift belongs to their restaurant and is open with `manual_entry` workflow.
- [ ] **AC-2E-02:** `POST /entry/{shift}` is registered and reachable (does not return 404 for a valid shift).
- [ ] **AC-2E-03:** `GET /entry/{shift}` redirects to login for unauthenticated users.
- [ ] **AC-2E-04:** `POST /entry/{shift}` returns 403 for a Biker user (role middleware).

### Authorization (AC-2E-05 through AC-2E-10)

- [ ] **AC-2E-05:** Restaurant Manager can view the entry form for their own restaurant's open `manual_entry` shift.
- [ ] **AC-2E-06:** Restaurant Manager receives 403 when attempting to submit trips for a shift belonging to a different restaurant.
- [ ] **AC-2E-07:** Admin can view the entry form for any restaurant's open `manual_entry` shift.
- [ ] **AC-2E-08:** Admin can submit trips for any restaurant's shift.
- [ ] **AC-2E-09:** ShiftPolicy@submitTrips returns `true` for Admin on any open shift.
- [ ] **AC-2E-10:** ShiftPolicy@submitTrips returns `false` for Restaurant Manager on another restaurant's shift.

### BR-01 Enforcement — Validation (AC-2E-11 through AC-2E-16)

- [ ] **AC-2E-11:** SubmitTripsRequest rejects with validation error when shift `workflow_type` is `live_tick` (BR-01).
- [ ] **AC-2E-12:** SubmitTripsRequest rejects with validation error when shift status is not `open` (draft, closed, approved, paid).
- [ ] **AC-2E-13:** SubmitTripsRequest rejects with validation error when `bikers` array is empty or missing.
- [ ] **AC-2E-14:** SubmitTripsRequest rejects with validation error when a `biker_id` in the submission does not exist in the database.
- [ ] **AC-2E-15:** SubmitTripsRequest rejects with validation error when a biker is not assigned to the shift (no `ShiftBiker` record for that `biker_id`).
- [ ] **AC-2E-16:** SubmitTripsRequest rejects with validation error when any `trips_count` is negative or not an integer.

### Submission Execution (AC-2E-17 through AC-2E-24)

- [ ] **AC-2E-17:** A valid submission updates all `ShiftBiker` records' `trips_count` to the submitted values.
- [ ] **AC-2E-18:** A valid submission redirects back to the tracking dashboard with a success flash message.
- [ ] **AC-2E-19:** Submitting trips for multiple bikers on the same shift updates each independently.
- [ ] **AC-2E-20:** Submitting `trips_count = 0` for a biker sets `trips_count` to exactly 0 (not NULL).
- [ ] **AC-2E-21:** Re-submission (shift still open) overwrites previous `trips_count` values.
- [ ] **AC-2E-22:** Submission with `close_shift = true` transitions the shift status to `closed` and sets `closed_at`.
- [ ] **AC-2E-23:** Submission without `close_shift` keeps the shift status as `open`.
- [ ] **AC-2E-24:** Partial submission (missing some assigned bikers) is rejected with validation error.

### Entry Form View (AC-2E-25 through AC-2E-31)

- [ ] **AC-2E-25:** The entry form displays the shift's restaurant name and shift ID.
- [ ] **AC-2E-26:** The entry form displays each assigned biker's name.
- [ ] **AC-2E-27:** The entry form displays an input field for each assigned biker's trip count, pre-filled with the current `trips_count`.
- [ ] **AC-2E-28:** The entry form contains hidden `biker_id` fields for each biker.
- [ ] **AC-2E-29:** The entry form includes a "Encerrar turno" checkbox.
- [ ] **AC-2E-30:** The entry form POSTs to `entry.store` route with CSRF token.
- [ ] **AC-2E-31:** The entry form shows a message when no bikers are assigned.

### Dashboard Integration (AC-2E-32)

- [ ] **AC-2E-32:** The tracking dashboard shows a "Registrar Viagens" button/link for `manual_entry` shifts (instead of the tick button).

### Regression (AC-2E-33)

- [ ] **AC-2E-33:** All existing 543 tests continue to pass without modification.

---

## 10. Security Considerations

- **Authorization:** Four layers of defense:
  1. **Middleware** (`role:restaurant_manager,admin`) — ensures only RM/Admin can access entry routes
  2. **SubmitTripsRequest@authorize()** — ensures RM can only submit for their own restaurant's shifts
  3. **SubmitTripsRequest validation rules** — ensures shift is open, workflow is `manual_entry`, bikers are assigned, trips are non-negative integers
  4. **ShiftPolicy@submitTrips** — explicit policy method for authorization
- **Input Validation:** Array validation on `bikers` with nested rules. Each `biker_id` validated as `required|integer|exists:bikers,id`. Each `trips_count` validated as `required|integer|min:0`.
- **Container Compliance:** All work is within `/workspaces/bikerflow`. No external access needed.
- **Financial Safety:** No direct financial computation in this phase. `trips_count` is an INTEGER update. Payout calculation uses the stored `trips_count` later in Phase 3.
- **CSRF Protection:** All POST forms use `@csrf` Blade directive (standard Laravel protection).
- **Mass Assignment:** No mass assignment in store — explicit loop updating each `ShiftBiker` individually with validated data.

---

## 11. Implementation Order

The Developer should implement in this order:

1. **ShiftPolicy@submitTrips** — Add the `submitTrips` method to the existing policy
2. **SubmitTripsRequest** — Create the form request with all validation rules
3. **ShiftEntryController** — Create with `show()` and `store()` methods
4. **Routes** — Add the `entry` route group to `routes/web.php`
5. **Blade View** — Create `entry/show.blade.php`
6. **Dashboard Integration** — Update `tracking/dashboard.blade.php` to show entry link for `manual_entry` shifts
7. **Verify** — Run all 543+ existing tests + new tests
