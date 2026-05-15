# Plan: Phase 2D — Live Tick Tracking

**Task ID:** Phase-2D
**Date:** 2026-05-15
**Planner Version:** 1.0
**Complexity:** Medium

---

## 1. Objective

Implement real-time trip count tracking for Restaurant Managers via a "Live Tick" workflow. This enables Restaurant Managers to increment a specific biker's trip count by 1 on an open shift whose tracking method is `live_tick`. Includes a live shift dashboard view, a tick endpoint with strict validation (TickTripRequest), and BR-01 enforcement to reject ticks on non-`live_tick` shifts. This is the first feature where Restaurant Managers interact with the system as authenticated actors (not just Admin).

---

## 2. Source References

### User Stories
- PRD §2A — Restaurant Manager persona: "At shift start, chooses between Live Tick (real-time + button) or End-of-Shift Entry. Increments delivery counts for assigned bikers."

### Business Rules
- **BR-01** — Workflow Locking: Once a shift starts, the `workflow_type` (Live vs. Manual) cannot be changed. Tick endpoint must reject if `workflow_type` is not `live_tick`.
- **BR-05** — Last Minute Biker: Only Admin can add/replace bikers once a shift has been initiated. (Context — does not block Restaurant Managers from ticking.)

### PRD Sections
- §2A — Restaurant Manager workflow (Live Tick + End-of-Shift Entry)
- §4 — BR-01 (Workflow Locking)

### Tech Doc Sections
- §3 — Business Logic & Formulas (Payout/Revenue — context only, no payout calc in this phase)
- §5 — Security & Guardrails (BR-01 enforcement)

---

## 3. Scope

### In Scope
1. `ShiftTrackingController` with two actions: `dashboard` (GET) and `tick` (POST)
2. `TickTripRequest` form request with four validation rules (shift open, workflow is `live_tick`, biker assigned, user is Restaurant Manager for shift's restaurant)
3. Web routes for live tick tracking protected by `auth` + `role:restaurant_manager` middleware
4. Blade view for the live shift tracking dashboard showing biker names, trip counts, and tick buttons
5. BR-01 enforcement at the validation layer (reject tick if workflow is not `live_tick`)
6. ShiftPolicy update to authorize Restaurant Managers for `tick` on their own restaurant's shifts
7. Navigation link for Restaurant Managers in the app layout

### Out of Scope
1. End-of-Shift Entry (manual total entry) — future Phase 2E
2. Payout calculation on tick — deferred to Phase 3
3. Shift creation/opening by Restaurant Managers — shift creation and opening remain Admin-only
4. Biker assignment/removal by Restaurant Managers — BR-05, Admin-only
5. Real-time WebSocket/polling updates — page refresh is sufficient for MVP
6. Shift closing by Restaurant Managers — remains Admin-only
7. AJAX/API JSON endpoints — form POST with redirect is sufficient for MVP

### Open Questions
1. **Should the dashboard show only open shifts, or also draft shifts?** — Assumption: Dashboard shows open shifts only (ticking is only possible on open shifts). Draft shifts have no started_at and cannot be ticked. This is consistent with PRD §2A ("At shift start, chooses...").
2. **What if a Restaurant Manager has no open shifts?** — Assumption: Dashboard shows a message "Nenhum turno aberto no momento" (No open shifts at this time).
3. **Should the tick button update the page dynamically (AJAX)?** — Assumption: No, MVP uses full page reload after POST redirect. Real-time updates are future enhancement.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | **Yes** | TickTripRequest validates `shift.workflow_type === WorkflowType::LiveTick`. Reject with 422 if not. Also enforced by controller logic as defense-in-depth. |
| BR-02 PIX Verification | No | Not relevant to tick tracking. |
| BR-03 Manual Release | No | Payout/release not triggered by tick. |
| BR-04 Granular Failure | No | No payments in this phase. |
| BR-05 Last Minute Biker | **Yes (context)** | Restaurant Managers cannot add/remove bikers. They can only tick trips for already-assigned bikers. No change needed — existing policy/middleware already enforces this. |
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

N/A — no new financial columns. The tick endpoint increments `shift_bikers.trips_count` (INTEGER), not a monetary column.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Controller | `app/Http/Controllers/RestaurantManager/ShiftTrackingController.php` | `dashboard()` and `tick()` actions for Restaurant Manager live tracking |
| Request | `app/Http/Requests/TickTripRequest.php` | Form request validating tick preconditions (BR-01, shift open, biker assigned, auth) |
| View | `resources/views/tracking/dashboard.blade.php` | Live shift tracking dashboard — biker list with tick buttons |
| Test | `tests/Feature/Controllers/ShiftTrackingControllerTest.php` | Feature tests for all tick tracking acceptance criteria |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Policy | `app/Policies/ShiftPolicy.php` | Add `tick(User, Shift): bool` method — Restaurant Manager can tick their own restaurant's shifts; Admin can tick any shift |
| Route | `routes/web.php` | Add new route group: `middleware(['auth', 'role:restaurant_manager'])` with `GET /tracking` (dashboard) and `POST /tracking/{shift}/tick` (tick). Also register `POST /tracking/tick` alternative under `role:admin` for admin access. |
| View | `resources/views/layouts/app.blade.php` | Add navigation link "Acompanhamento" (Tracking) for Restaurant Managers in the nav bar |
| Gate | `app/Providers/AppServiceProvider.php` | Add `Gate::define('tick-shift', ...)` for explicit tick authorization |

---

## 7. Pseudocode

### Critical Business Logic — Tick Action

```
CONTROLLER ShiftTrackingController@tick(request, shift):
    // Authorization already validated by TickTripRequest + Policy
    
    shiftBiker = ShiftBiker::where('shift_id', shift.id)
                            ->where('biker_id', request.biker_id)
                            ->first()
    
    // Defense-in-depth: should never hit this due to validation, but guard anyway
    IF shiftBiker IS NULL:
        ABORT 404
    
    // Increment trips_count by 1
    shiftBiker->trips_count += 1
    shiftBiker->save()
    
    REDIRECT back to dashboard WITH success flash "Viagem registrada!"

CONTROLLER ShiftTrackingController@dashboard(request):
    user = request.user()
    
    IF user.isAdmin():
        shifts = Shift::where('status', ShiftStatus::Open)
                       ->with('shiftBikers.biker')
                       ->orderBy('started_at', 'desc')
                       ->get()
    ELSE IF user.isRestaurantManager():
        shifts = Shift::where('status', ShiftStatus::Open)
                       ->where('restaurant_id', user.restaurant_id)
                       ->with('shiftBikers.biker')
                       ->orderBy('started_at', 'desc')
                       ->get()
    ELSE:
        ABORT 403
    
    RETURN view('tracking.dashboard', compact('shifts'))
```

### TickTripRequest Validation Logic

```
CLASS TickTripRequest EXTENDS FormRequest:
    
    authorize():
        user = this.user()
        shift = this.route('shift')
        
        // Admin can tick any shift
        IF user.isAdmin():
            RETURN true
        
        // Restaurant Manager can only tick their own restaurant's shift
        IF user.isRestaurantManager():
            RETURN shift.restaurant_id === user.restaurant_id
        
        RETURN false
    
    rules():
        RETURN {
            'biker_id': ['required', 'integer', 'exists:bikers,id']
        }
    
    withValidator(validator):
        validator.after(FUNCTION (validator):
            shift = this.route('shift')
            
            // Rule 1: Shift must be open
            IF shift.status !== ShiftStatus::Open:
                validator.errors.add('shift', 'Somente turnos abertos podem receber marcações.')
                RETURN   // Skip further checks if shift not open
            
            // Rule 2 (BR-01): Shift workflow must be live_tick
            IF shift.workflow_type !== WorkflowType::LiveTick:
                validator.errors.add('workflow_type', 'Este turno não usa contagem em tempo real.')
                RETURN
            
            // Rule 3: Biker must be assigned to this shift
            bikerId = this.input('biker_id')
            IF bikerId IS NOT NULL:
                isAssigned = ShiftBiker::where('shift_id', shift.id)
                                        ->where('biker_id', bikerId)
                                        ->exists()
                IF NOT isAssigned:
                    validator.errors.add('biker_id', 'Este entregador não está atribuído a este turno.')
        )
```

### ShiftPolicy@tick

```
POLICY ShiftPolicy@tick(user, shift):
    IF user.isAdmin():
        RETURN true
    
    IF user.isRestaurantManager():
        RETURN shift.restaurant_id === user.restaurant_id
               AND shift.status === ShiftStatus::Open
    
    RETURN false
```

### State Transitions

```
Shift: [draft] --(Admin opens)--> [open] ──────────────────────────────────────> [closed] --> ...
                                        │
                                        │ Restaurant Manager can TICK here
                                        │ (increment trips_count on ShiftBiker)
                                        │
                                        └── BR-01 guard: only if workflow_type = live_tick
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware | Name |
|--------|-----|-------------------|------|------------|------|
| GET | `/tracking` | `ShiftTrackingController@dashboard` | Restaurant Manager or Admin | `auth`, `role:restaurant_manager,admin` | `tracking.dashboard` |
| POST | `/tracking/{shift}/tick` | `ShiftTrackingController@tick` | Restaurant Manager or Admin | `auth`, `role:restaurant_manager,admin` | `tracking.tick` |

**Middleware note:** The existing `EnsureUserRole` middleware accepts multiple roles: `role:restaurant_manager,admin`. This allows Admin users to also access the tracking dashboard for observation/testing, while the `TickTripRequest@authorize()` and `ShiftPolicy@tick()` enforce that Restaurant Managers can only tick their own restaurant's shifts.

### Navigation Update

In `layouts/app.blade.php`, add a nav link visible when `auth()->user()->isRestaurantManager()`:
```
@if(auth()->user()->isRestaurantManager())
    <a href="{{ route('tracking.dashboard') }}">Acompanhamento</a>
@endif
```

---

## 8. Edge Cases

1. **Shift not open** — TickTripRequest must reject with validation error. Trip count must NOT change.
2. **Shift workflow is `manual_entry`** — BR-01: TickTripRequest must reject. This is the core BR-01 enforcement.
3. **Biker not assigned to shift** — TickTripRequest must reject. Prevents ticking arbitrary bikers.
4. **Restaurant Manager ticking another restaurant's shift** — TickTripRequest@authorize() must return false → 403.
5. **Restaurant Manager with no restaurant_id** — If user has role `restaurant_manager` but `restaurant_id` is null, dashboard shows no shifts and tick is denied. Edge case from bad data, but must not crash.
6. **Concurrent ticks on same biker** — Two simultaneous POST requests. Both should succeed (increment by 1 each). MySQL InnoDB row-level locking on UPDATE handles this. No explicit locking needed for single-row increment.
7. **Tick on a shift that was just closed** — Race condition: shift is open when page loads but closed when tick arrives. TickTripRequest validates against fresh shift state → validation error. User must reload.
8. **Non-existent shift ID in URL** — Route model binding returns 404 automatically.
9. **Non-existent biker_id in POST body** — Validation rule `exists:bikers,id` catches this before custom logic.
10. **Biker assigned to shift but biker record deactivated** — Biker is still assigned (ShiftBiker record exists). Tick should still work — the biker was active when assigned. Only `ShiftBiker::where(...)->exists()` check matters.
11. **Unauthenticated access** — `auth` middleware redirects to login.
12. **Biker user trying to access tracking** — `role:restaurant_manager,admin` middleware returns 403.
13. **Admin ticking a shift** — Allowed. Admin can tick any shift. Useful for testing and override scenarios.
14. **Empty dashboard (no open shifts)** — Show "Nenhum turno aberto no momento" message.
15. **Shift with assigned bikers but no trips yet** — Dashboard shows all bikers with trips_count = 0 and tick buttons enabled.

---

## 9. Acceptance Criteria

These are the **exact conditions** the Tester will verify. Each must be atomic and unambiguous.

### Route & Middleware (AC-2D-01 through AC-2D-04)

- [ ] **AC-2D-01:** `GET /tracking` returns 200 for an authenticated Restaurant Manager.
- [ ] **AC-2D-02:** `POST /tracking/{shift}/tick` is registered and reachable (does not return 404 for a valid shift).
- [ ] **AC-2D-03:** `GET /tracking` redirects to login for unauthenticated users.
- [ ] **AC-2D-04:** `POST /tracking/{shift}/tick` returns 403 for a Biker user (role middleware).

### Authorization (AC-2D-05 through AC-2D-10)

- [ ] **AC-2D-05:** Restaurant Manager can access dashboard for their own restaurant's shifts.
- [ ] **AC-2D-06:** Restaurant Manager receives 403 when attempting to tick a shift belonging to a different restaurant.
- [ ] **AC-2D-07:** Admin can access the tracking dashboard (role middleware allows `admin`).
- [ ] **AC-2D-08:** Admin can tick trips on any restaurant's shift.
- [ ] **AC-2D-09:** ShiftPolicy@tick returns `true` for Admin on any open shift.
- [ ] **AC-2D-10:** ShiftPolicy@tick returns `false` for Restaurant Manager on another restaurant's shift.

### Tick Validation — BR-01 Enforcement (AC-2D-11 through AC-2D-16)

- [ ] **AC-2D-11:** TickTripRequest rejects with validation error when shift `workflow_type` is `manual_entry` (BR-01).
- [ ] **AC-2D-12:** TickTripRequest rejects with validation error when shift status is not `open` (e.g., draft, closed, approved, paid).
- [ ] **AC-2D-13:** TickTripRequest rejects with validation error when `biker_id` is not provided.
- [ ] **AC-2D-14:** TickTripRequest rejects with validation error when `biker_id` does not exist in the database.
- [ ] **AC-2D-15:** TickTripRequest rejects with validation error when the biker is not assigned to the shift (no ShiftBiker record).
- [ ] **AC-2D-16:** TickTripRequest rejects with validation error when `biker_id` is not an integer.

### Tick Execution (AC-2D-17 through AC-2D-22)

- [ ] **AC-2D-17:** A valid tick increments the specified biker's `trips_count` by exactly 1 on an open `live_tick` shift.
- [ ] **AC-2D-18:** A valid tick redirects back to the tracking dashboard with a success flash message.
- [ ] **AC-2D-19:** Multiple sequential ticks on the same biker increment `trips_count` correctly (e.g., 0 → 1 → 2 → 3).
- [ ] **AC-2D-20:** Ticking one biker does not affect another biker's `trips_count` on the same shift.
- [ ] **AC-2D-21:** Tick on a closed shift returns validation error and `trips_count` is unchanged.
- [ ] **AC-2D-22:** Tick on a draft shift returns validation error and `trips_count` is unchanged.

### Dashboard View (AC-2D-23 through AC-2D-30)

- [ ] **AC-2D-23:** Dashboard shows all open shifts for the Restaurant Manager's restaurant.
- [ ] **AC-2D-24:** Dashboard does not show shifts from other restaurants.
- [ ] **AC-2D-25:** Dashboard does not show draft, closed, approved, or paid shifts.
- [ ] **AC-2D-26:** Each assigned biker's name is displayed on the dashboard.
- [ ] **AC-2D-27:** Each assigned biker's current `trips_count` is displayed on the dashboard.
- [ ] **AC-2D-28:** Each assigned biker has a tick button (form POST) pointing to `tracking.tick` route.
- [ ] **AC-2D-29:** When no open shifts exist, dashboard displays "Nenhum turno aberto no momento."
- [ ] **AC-2D-30:** Dashboard does not display the "Assign Biker" form (that's Admin-only functionality).

### Navigation (AC-2D-31)

- [ ] **AC-2D-31:** The app layout nav bar shows "Acompanhamento" link for Restaurant Manager users pointing to `tracking.dashboard`.

### Regression (AC-2D-32)

- [ ] **AC-2D-32:** All existing 482 tests continue to pass without modification.

---

## 10. Security Considerations

- **Authorization:** Four layers of defense:
  1. **Middleware** (`role:restaurant_manager,admin`) — ensures only RM/Admin can access routes
  2. **TickTripRequest@authorize()** — ensures RM can only tick their own restaurant's shifts
  3. **TickTripRequest validation rules** — ensures shift is open, workflow is `live_tick`, biker is assigned
  4. **ShiftPolicy@tick** — explicit policy method for authorization via `$this->authorize('tick', $shift)`
- **Input Validation:** `biker_id` is validated as `required|integer|exists:bikers,id`, plus custom rule checking ShiftBiker assignment
- **Container Compliance:** All work is within `/workspaces/bikerflow`. No external access needed.
- **Financial Safety:** No direct financial computation in this phase. `trips_count` is an INTEGER increment. Payout calculation uses the stored `trips_count` later in Phase 3.
- **CSRF Protection:** All POST forms use `@csrf` Blade directive (standard Laravel protection).
- **Mass Assignment:** No mass assignment in tick — explicit `shiftBiker->trips_count += 1; shiftBiker->save()`.

---

## 11. Implementation Order

The Developer should implement in this order:

1. **ShiftPolicy@tick** — Add the `tick` method to the existing policy
2. **AppServiceProvider** — Register `tick-shift` gate (optional, for explicit gate usage)
3. **TickTripRequest** — Create the form request with all validation rules
4. **ShiftTrackingController** — Create with `dashboard()` and `tick()` methods
5. **Routes** — Add the `tracking` route group to `routes/web.php`
6. **Blade View** — Create `tracking/dashboard.blade.php`
7. **Layout Nav** — Update `layouts/app.blade.php` with nav link for Restaurant Managers
8. **Verify** — Run all 482+ existing tests + new tests
