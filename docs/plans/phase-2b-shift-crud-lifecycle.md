# Plan: Phase 2B — Shift CRUD & Lifecycle

**Task ID:** Phase-2B
**Date:** 2026-05-14
**Planner Version:** 1.0
**Complexity:** Medium

---

## 1. Objective

Implement the full Shift CRUD lifecycle for Admin users: create shifts with an immutable tracking method (BR-01), list and view shifts, edit draft shifts, and close open shifts. This plan wires the existing Shift model (with its `saving` hook enforcing BR-01 and state transitions) to HTTP endpoints via a controller, form requests, routes, and Blade views.

---

## 2. Source References

### User Stories
- (Implied) Admin manages shifts end-to-end: create → list → view → edit → close
- US-02: Holiday shift rate override (edit `restaurant_rate` on existing shift)

### Business Rules
- BR-01: Workflow Locking — tracking method (`workflow_type`) is locked once a shift leaves draft status
- BR-05: Last Minute Biker — only Admin can manage shifts (reinforced by `role:admin` middleware)

### PRD Sections
- §2C: Company Manager — manages contracts, reviews shifts, controls lifecycle
- §3: Rate & Revenue Management — `restaurant_rate` stored per shift
- §4: BR-01 — Workflow Locking rule table entry

### Tech Doc Sections
- §3: Business Logic & Formulas — Biker Payout Formula (referenced for context; not calculated in this phase)
- §5: Security & Guardrails — BR-01 Workflow Locking, Audit Logging

### Existing ADRs
- ADR-001 (`docs/adr/001-core-payout-schema.md`) — Shift state machine, BR-01 workflow locking
- ADR-002 (`docs/adr/002-auth-roles-magic-link.md`) — Auth, roles, middleware

---

## 3. Scope

### In Scope
1. `ShiftController` with 7 actions: `index`, `create`, `store`, `show`, `edit`, `update`, `close`
2. `StoreShiftRequest` — validates shift creation (restaurant_id, workflow_type, restaurant_rate)
3. `UpdateShiftRequest` — validates shift updates (only draft shifts; workflow_type immutable once non-draft)
4. `CloseShiftRequest` — validates shift close action (shift must be `open`)
5. Web routes under `auth` + `role:admin` middleware group for all shift CRUD + close actions
6. Blade views: `shifts/index`, `shifts/create`, `shifts/edit`, `shifts/show`
7. Shared layout view (`layouts/app.blade.php`) for consistent nav/footer
8. `ShiftPolicy@close` method — admin-only close authorization
9. Flash messages for success/error feedback on all state-changing actions
10. `created_by` auto-set to authenticated admin's user ID on shift creation

### Out of Scope
1. Shift Biker assignment (Phase 3 scope — BR-05)
2. Live Tick tracking UI (increment trip counts — future phase)
3. Manual Entry tracking UI (enter trip totals — future phase)
4. Payout calculation display (Phase 3 — PayoutService integration)
5. Payment release/approval workflow (Phase 4)
6. PDF Trip Sheet generation (US-01)
7. API endpoints (JSON) — web routes only
8. Restaurant Manager or Biker shift views — Admin only for this phase
9. Soft deletes or shift deletion — not in requirements

### Open Questions
1. **Q1:** Should Admin be able to delete draft shifts? The task mentions only CRUD + close, not destroy. **Assumption:** No — destroy is out of scope. Can be added later.
2. **Q2:** Should the shift list support filtering by status or restaurant? **Assumption:** Basic filtering by status (query param `?status=draft|open|closed`) is in scope as it's essential for admin workflow, but pagination is simple (no search/sort beyond status).

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | **Yes** | `StoreShiftRequest` requires `workflow_type` on creation. `UpdateShiftRequest` forbids `workflow_type` changes if shift is not in `draft` status. The Shift model's `saving` hook is the final enforcer — throws `WorkflowLockedException` if violated. Controller must catch this exception and return with error flash. |
| BR-02 PIX Verification | No | No PIX operations in this phase |
| BR-03 Manual Release | No | No payment release in this phase |
| BR-04 Granular Failure | No | No payments in this phase |
| BR-05 Last Minute Biker | **Yes** | All shift routes use `role:admin` middleware. Only Admin can create/edit/close shifts. `ShiftPolicy@close` returns true only for admin. |
| BR-06 Payment Retries | No | No payments in this phase |

---

## 5. Schema Changes

### New Tables
No new tables.

### Modified Tables
No modifications to existing tables. All required columns already exist:
- `shifts.restaurant_id` (FK → restaurants)
- `shifts.workflow_type` (VARCHAR(20), default 'live_tick')
- `shifts.status` (VARCHAR(20), default 'draft')
- `shifts.restaurant_rate` (DECIMAL(12,2))
- `shifts.created_by` (FK → users, nullable)
- `shifts.started_at` (TIMESTAMP, nullable)
- `shifts.closed_at` (TIMESTAMP, nullable)

### Indexes
No new indexes. Existing indexes cover this phase:
- `idx_shifts_restaurant_status` on `shifts(restaurant_id, status)`
- `idx_shifts_status` on `shifts(status)`

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| restaurant_rate | shifts | DECIMAL(12,2) | Yes — future PayoutService reads this; validation ensures numeric with 2 decimal places |

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | Shift CRUD + close actions (admin-only) |
| Request | `app/Http/Requests/StoreShiftRequest.php` | Validation rules for shift creation |
| Request | `app/Http/Requests/UpdateShiftRequest.php` | Validation rules for shift updates (draft only) |
| Request | `app/Http/Requests/CloseShiftRequest.php` | Validation/authorization for shift close action |
| View | `resources/views/layouts/app.blade.php` | Shared layout with nav, auth checks, flash messages |
| View | `resources/views/shifts/index.blade.php` | Shift list with status filter |
| View | `resources/views/shifts/create.blade.php` | Create shift form (restaurant select, workflow_type, restaurant_rate) |
| View | `resources/views/shifts/edit.blade.php` | Edit shift form (draft only; workflow_type read-only if non-draft) |
| View | `resources/views/shifts/show.blade.php` | Shift detail view with close button, lifecycle info |
| Test | `tests/Feature/Controllers/ShiftControllerTest.php` | Feature tests for all controller actions |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Routes | `routes/web.php` | Add `Route::resource('shifts', ShiftController::class)` + custom `close` route, wrapped in `auth` + `role:admin` middleware group |
| Policy | `app/Policies/ShiftPolicy.php` | Add `close(User, Shift): bool` method — admin-only |
| View | `resources/views/dashboard.blade.php` | Add link to shifts index in nav (admin conditional) |

---

## 7. Pseudocode

### Critical Business Logic

#### StoreShiftRequest Validation

```
RULES:
  restaurant_id    — required|exists:restaurants,id
  workflow_type    — required|in:live_tick,manual_entry
  restaurant_rate  — required|decimal:0,2|min:0|max:9999999999.99

AFTER_VALIDATION (prepareForValidation):
  No transformation needed — enum cast on model handles it
```

#### UpdateShiftRequest Validation

```
RULES:
  restaurant_rate  — sometimes|required|decimal:0,2|min:0|max:9999999999.99
  workflow_type    — sometimes|in:live_tick,manual_entry
  restaurant_id    — sometimes|exists:restaurants,id

CUSTOM_RULE (WorkflowTypeLock):
  IF shift.status != 'draft' AND request has workflow_type:
    FAIL with "Cannot change tracking method after shift has left draft status"

NOTE: This is a defense-in-depth check. The Shift model's saving hook 
      is the authoritative BR-01 enforcer. The form request provides 
      user-friendly error messages before the exception is thrown.
```

#### CloseShiftRequest Validation

```
CUSTOM_RULE (ShiftMustBeOpen):
  IF shift.status != 'open':
    FAIL with "Only open shifts can be closed"

AFTER_VALIDATION:
  No additional data needed — controller handles the transition
```

#### ShiftController@store

```
FUNCTION store(StoreShiftRequest $request):
  data = $request->validated()
  data['created_by'] = $request->user()->id
  data['status'] = 'draft'  // Explicit — model default also sets this
  
  shift = Shift::create(data)
  
  RETURN redirect()->route('shifts.show', shift)
    ->with('success', 'Turno criado com sucesso.')
```

#### ShiftController@close

```
FUNCTION close(CloseShiftRequest $request, Shift $shift):
  TRY:
    shift->status = 'closed'
    shift->closed_at = now()
    shift->save()
    
    RETURN redirect()->route('shifts.show', shift)
      ->with('success', 'Turno encerrado com sucesso.')
  
  CATCH WorkflowLockedException:
    // Should not happen for status change, but defense-in-depth
    RETURN back()->with('error', 'Erro ao encerrar turno.')
  CATCH RuntimeException:
    RETURN back()->with('error', 'Transição de status inválida.')
```

#### ShiftController@update

```
FUNCTION update(UpdateShiftRequest $request, Shift $shift):
  TRY:
    shift->fill($request->validated())
    shift->save()
    
    RETURN redirect()->route('shifts.show', shift)
      ->with('success', 'Turno atualizado com sucesso.')
  
  CATCH WorkflowLockedException:
    RETURN back()->with('error', 'Não é possível alterar o método de rastreamento.')
    ->withInput()
```

#### ShiftController@index

```
FUNCTION index(Request $request):
  query = Shift::with('restaurant')
  
  IF $request->has('status') AND $request->status IN ['draft','open','closed','approved','paid']:
    query->where('status', $request->status)
  
  shifts = query->orderBy('created_at', 'desc')->paginate(15)
  
  RETURN view('shifts.index', compact('shifts'))
```

### State Transitions

```
[draft] ──(Admin creates shift)──▶ [draft]   ← Created via store()
  │
  ├──(Admin edits)────────────────▶ [draft]   ← Updated via update()
  │                                     │
  │                                     └──(Admin opens)──▶ [open]    ← Future: transition action
  │
  └──(Future: open action)────────▶ [open]    ← Not in this phase's scope
                                       │
                                       └──(Admin closes)──▶ [closed]  ← Closed via close()

Note: The draft→open transition is handled by the Shift model's saving hook 
      (AC-36b). This phase's controller can set status to 'open' via update 
      on a draft shift. A dedicated "open" action is NOT in scope — the admin 
      updates the shift status through the edit form or a future dedicated action.
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware | Route Name |
|--------|-----|-------------------|------|------------|------------|
| GET | `/shifts` | `ShiftController@index` | Admin | `auth,role:admin` | `shifts.index` |
| GET | `/shifts/create` | `ShiftController@create` | Admin | `auth,role:admin` | `shifts.create` |
| POST | `/shifts` | `ShiftController@store` | Admin | `auth,role:admin` | `shifts.store` |
| GET | `/shifts/{shift}` | `ShiftController@show` | Admin | `auth,role:admin` | `shifts.show` |
| GET | `/shifts/{shift}/edit` | `ShiftController@edit` | Admin | `auth,role:admin` | `shifts.edit` |
| PUT/PATCH | `/shifts/{shift}` | `ShiftController@update` | Admin | `auth,role:admin` | `shifts.update` |
| POST | `/shifts/{shift}/close` | `ShiftController@close` | Admin | `auth,role:admin` | `shifts.close` |

**Route registration pattern:**
```
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::resource('shifts', ShiftController::class);
    Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
});
```

**Important:** The custom `close` route must be registered AFTER the resource route to avoid conflicts. The `close` route uses POST (not PUT/PATCH) because it's an action, not a resource update.

---

## 8. Edge Cases

1. **Creating a shift with an inactive restaurant** — `StoreShiftRequest` should validate that the restaurant exists and is `active = true`. The `exists:restaurants,id` rule checks existence but not the `active` flag. Add a custom rule to reject inactive restaurants.
2. **Editing a non-draft shift** — `ShiftController@edit` should render the view with workflow_type as read-only. `UpdateShiftRequest` rejects workflow_type changes on non-draft shifts. Even if the view accidentally sends the field, validation blocks it.
3. **Closing an already-closed shift** — `CloseShiftRequest` validates status must be `open`. Attempting to close a `closed`/`draft`/`approved`/`paid` shift returns validation error.
4. **Closing a draft shift** — Blocked by `CloseShiftRequest` (must be `open`) AND by the Shift model's state transition guard (AC-38a: draft cannot skip to closed). Defense-in-depth.
5. **Double-submit on close** — No idempotency mechanism in this phase. The status check prevents double-close. Consider adding CSRF protection (Laravel handles this natively).
6. **Workflow type locking on update** — If admin submits edit form for a non-draft shift that includes the old workflow_type value (same as current), the `UpdateShiftRequest` should NOT reject it. Only reject if the value CHANGES. The Shift model's saving hook also checks `isDirty('workflow_type')`, so same-value submissions pass through.
7. **Empty shift list** — Index view should display a friendly "No shifts found" message when no shifts exist or no shifts match the status filter.
8. **Non-existent shift ID** — Laravel's implicit route model binding returns 404 automatically.
9. **CSRF on all forms** — All forms must include `@csrf` directive. Laravel handles this natively.
10. **created_by auto-set** — `store` action sets `created_by` from `auth()->id()`. The field should NOT be in the form request's validated data — set it in the controller.
11. **restaurant_rate precision** — The `decimal:0,2` validation rule ensures exactly 2 decimal places. Values like `15` or `15.5` should be accepted and stored as `15.00` and `15.50` respectively (DECIMAL column handles this).
12. **Status filter value validation** — The `index` action should only accept valid ShiftStatus values as the `status` query param. Invalid values should be ignored (show all).

---

## 9. Acceptance Criteria

### Shift Creation (Store)

- [ ] AC-2B-01: Admin can view the shift creation form at `GET /shifts/create`
- [ ] AC-2B-02: Admin can create a shift with `restaurant_id`, `workflow_type` = `live_tick`, `restaurant_rate` = `15.00` → redirected to `shifts.show` with success flash
- [ ] AC-2B-03: Admin can create a shift with `workflow_type` = `manual_entry` → shift stored correctly
- [ ] AC-2B-04: Creating a shift without `restaurant_id` returns validation error
- [ ] AC-2B-05: Creating a shift without `workflow_type` returns validation error
- [ ] AC-2B-06: Creating a shift without `restaurant_rate` returns validation error
- [ ] AC-2B-07: Creating a shift with `restaurant_rate` = `-1.00` returns validation error (min:0)
- [ ] AC-2B-08: New shift has `status` = `draft` by default
- [ ] AC-2B-09: New shift has `created_by` set to the authenticated admin's user ID
- [ ] AC-2B-10: Creating a shift with a non-existent restaurant_id returns validation error
- [ ] AC-2B-11: Creating a shift with an inactive restaurant returns validation error

### Shift Listing (Index)

- [ ] AC-2B-12: Admin can view shift list at `GET /shifts`
- [ ] AC-2B-13: Shift list displays restaurant name, workflow_type, status, restaurant_rate, created_at
- [ ] AC-2B-14: Shift list is ordered by `created_at` descending (newest first)
- [ ] AC-2B-15: Shift list is paginated (15 per page)
- [ ] AC-2B-16: Admin can filter shifts by status via `?status=draft` query parameter
- [ ] AC-2B-17: Invalid status filter value is ignored (shows all shifts)
- [ ] AC-2B-18: Unauthenticated user accessing `/shifts` is redirected to `/login`
- [ ] AC-2B-19: Non-admin user accessing `/shifts` receives 403 Forbidden

### Shift Detail (Show)

- [ ] AC-2B-20: Admin can view shift details at `GET /shifts/{id}`
- [ ] AC-2B-21: Show view displays: restaurant name, workflow_type, status, restaurant_rate, started_at, closed_at, created_by user name
- [ ] AC-2B-22: Show view includes "Close Shift" button only when status is `open`
- [ ] AC-2B-23: Show view includes "Edit" button only when status is `draft`
- [ ] AC-2B-24: Accessing a non-existent shift ID returns 404

### Shift Edit (Update)

- [ ] AC-2B-25: Admin can view the edit form at `GET /shifts/{id}/edit`
- [ ] AC-2B-26: Edit form pre-fills with current shift data
- [ ] AC-2B-27: Admin can update `restaurant_rate` on a draft shift → redirected to `shifts.show` with success flash
- [ ] AC-2B-28: Admin can update `workflow_type` on a draft shift → change is persisted
- [ ] AC-2B-29: Updating `workflow_type` on a non-draft (open/closed) shift returns validation error (BR-01)
- [ ] AC-2B-30: Updating a shift with invalid `restaurant_rate` (negative, non-numeric) returns validation error
- [ ] AC-2B-31: Updating with same `workflow_type` value on a non-draft shift succeeds (no false positive)

### Shift Close

- [ ] AC-2B-32: Admin can close an open shift via `POST /shifts/{id}/close`
- [ ] AC-2B-33: Closing a shift sets `status` to `closed` and `closed_at` to current timestamp
- [ ] AC-2B-34: Closing a draft shift returns validation error
- [ ] AC-2B-35: Closing an already-closed shift returns validation error
- [ ] AC-2B-36: Closing an approved shift returns validation error
- [ ] AC-2B-37: After close, admin is redirected to `shifts.show` with success flash

### Authorization & Security

- [ ] AC-2B-38: All shift routes require authentication (`auth` middleware)
- [ ] AC-2B-39: All shift routes require admin role (`role:admin` middleware)
- [ ] AC-2B-40: Non-admin (RestaurantManager, Biker) receives 403 on any shift route
- [ ] AC-2B-41: `ShiftPolicy@close` returns `true` only for admin users

### Regression

- [ ] AC-2B-42: All existing 333 tests continue to pass after implementation
- [ ] AC-2B-43: Shift model's `saving` hook (BR-01, AC-36→AC-38a) still works correctly — controller does not bypass model-level guards

### Views (Smoke Tests)

- [ ] AC-2B-44: `shifts/index` view renders without errors when shifts exist
- [ ] AC-2B-45: `shifts/index` view renders "No shifts found" when no shifts exist
- [ ] AC-2B-46: `shifts/create` view renders restaurant dropdown populated from active restaurants
- [ ] AC-2B-47: `shifts/edit` view shows workflow_type as read-only/disabled when shift is not in draft

---

## 10. Security Considerations

- **Authorization:** All shift routes wrapped in `middleware(['auth', 'role:admin'])`. Defense-in-depth via `ShiftPolicy` (registered for Shift model in `AppServiceProvider`). Controller methods should call `$this->authorize('view', $shift)`, `$this->authorize('update', $shift)`, etc.
- **Input Validation:** Three dedicated form requests validate all input. No mass-assignment vulnerability — Shift model uses `$fillable`. `created_by` is set server-side, never from user input.
- **Container Compliance:** All operations run within `/workspaces/bikerflow` inside the Docker container. No external API calls. No access outside the dev container.
- **Financial Safety:** `restaurant_rate` validated as `decimal:0,2` with `min:0`. Stored as `DECIMAL(12,2)`. BCMath not needed in this phase (no calculations — only storage/display).
- **CSRF Protection:** All state-changing forms use `@csrf` Blade directive. Laravel's `VerifyCsrfToken` middleware active by default.
- **Route Model Binding:** Uses Laravel's implicit route model binding with `{shift}` parameter, ensuring 404 for non-existent IDs and automatic type safety.

---

## 11. Implementation Notes for Developer

### Controller Location
Place `ShiftController` in `app/Http/Controllers/Admin/` namespace to keep admin controllers organized. The full namespace is `App\Http\Controllers\Admin\ShiftController`.

### Layout View
Create `resources/views/layouts/app.blade.php` as a shared layout. Structure:
- HTML5 boilerplate with Tailwind via `@vite`
- Top nav bar with BikerFlow branding, conditional links based on user role
- Flash message display area (`@if(session('success'))` / `@if(session('error'))`)
- `@yield('content')` section

Existing views (`dashboard.blade.php`, `login.blade.php`) do NOT need to be migrated to this layout — they remain standalone. Only new shift views use the layout.

### Dashboard Nav Link
Add a "Turnos" (Shifts) link to the existing `dashboard.blade.php` nav, visible only to admin users:
```
@if(auth()->user()->isAdmin())
  <a href="{{ route('shifts.index') }}" class="...">Turnos</a>
@endif
```

### ShiftPolicy@close
Add method to existing `ShiftPolicy`:
```php
public function close(User $user, Shift $shift): bool
{
    return $user->isAdmin();
}
```

### Inactive Restaurant Custom Rule
In `StoreShiftRequest`, use a Closure rule to check `active` status:
```
'restaurant_id' => [
    'required',
    'exists:restaurants,id',
    function ($attribute, $value, $fail) {
        $restaurant = Restaurant::find($value);
        if ($restaurant && !$restaurant->active) {
            $fail('O restaurante selecionado está inativo.');
        }
    },
],
```

### Edit View Conditional Fields
In `shifts/edit.blade.php`, the `workflow_type` field should be:
- **Draft shift:** Editable (radio buttons or select)
- **Non-draft shift:** Read-only (display current value as text, no form field)

This prevents user confusion even though the server-side validation would block changes.

### Status Labels (Portuguese)
Display shift status in Portuguese on views:
- `draft` → "Rascunho"
- `open` → "Aberto"
- `closed` → "Encerrado"
- `approved` → "Aprovado"
- `paid` → "Pago"

Workflow type labels:
- `live_tick` → "Contagem em Tempo Real"
- `manual_entry` → "Entrada Manual"

### Test File Location
Feature tests go in `tests/Feature/Controllers/ShiftControllerTest.php`. Use `RefreshDatabase` trait. Use existing factories (`ShiftFactory`, `RestaurantFactory`, `UserFactory`).

### Test Pattern
Each test should follow the Arrange-Act-Assert pattern:
1. **Arrange:** Create necessary entities (admin user, restaurant, shift) using factories
2. **Act:** Make HTTP request (`$this->actingAs($admin)->get('/shifts')`)
3. **Assert:** Check response status, session data, database state
