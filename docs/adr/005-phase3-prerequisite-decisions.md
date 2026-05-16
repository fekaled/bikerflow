# ADR-005: Phase 3 Payout Engine — Prerequisite Decisions

**Date:** 2026-05-15
**Status:** ✅ Accepted
**Decision Maker:** Manual (Product Owner)
**Task ID:** Phase 3 Prerequisites
**Pipeline:** — (pre-planning decision)
**Business Rules:** BR-02, BR-03, BR-04, BR-05
**Related Plan:** `docs/plans/phase-3-*` (to be created)

---

## Context

Phase 2 (Auth, Shift CRUD, Biker Assignment, Live Tick, End-of-Shift Entry) left five open questions unresolved. All five directly affect the design of Phase 3 (Payout Engine). Without resolution, the Planner cannot produce a correct blueprint for payout triggering, financial snapshotting, eligibility, notifications, or contestation handling.

The questions originated from:

| Source | Question |
|--------|----------|
| Phase 2E OQ-1 | Does trip submission auto-close the shift, or is closing Admin-only? |
| Phase 2C Q2 | Are `base_fee` / `biker_rate` snapshotted at assignment time? |
| Phase 2C Q1 | Do inactive bikers get paid for past shifts? |
| Phase 2A OQ-1 | Can bikers without User accounts receive payouts? |
| Phase 2E Q4 | Can trips be re-submitted before close — is partial payout ever computed? |

---

## Decisions

### Decision 1: Shift Closing is Admin-Only, Payout is Always Post-Close

> **Answer to:** Phase 2E OQ-1
>
> The "Encerrar turno" checkbox on the Restaurant Manager trip submission form is **removed** (or ignored). Only Admin can close a shift via the existing `ShiftController@close` action. The payout engine triggers **after** the shift transitions to `closed`.

**Consequences:**
- RM submits trips → `trips_count` updated, shift stays `open`.
- Admin reviews and closes → shift → `closed`.
- Payout engine runs only on `closed` shifts.
- RM re-submission before Admin close is allowed (overwrites `trips_count`).
- No partial or interim payout calculation.

---

### Decision 2: Financial Rates Snapshotted at Assignment Time

> **Answer to:** Phase 2C Q2
>
> `shift_bikers.base_fee` and `shift_bikers.biker_rate` are **snapshotted** at the moment of assignment. They are **not** live-linked to `bikers.rate_per_trip` or any other default. Changing a biker's profile rate does **not** retroactively affect past or existing shift assignments.

**Consequences:**
- The payout formula reads `shift_bikers.base_fee` and `shift_bikers.biker_rate` directly — these are the immutable source of truth for that shift-biker row.
- `Biker.rate_per_trip` serves only as a **default suggestion** when assigning a biker to a new shift.
- No migration change needed — the columns already exist on `shift_bikers`. The `AssignBikerRequest` / controller already populates them at assignment time.
- This is already the de facto behavior; this decision confirms it as the intended design.

---

### Decision 3: Inactive Bikers Blocked from New Assignments, Existing Assignments Preserved

> **Answer to:** Phase 2C Q1
>
- A biker **must be `active = true`** to be assigned to a shift. The `AssignBikerRequest` already enforces this.
- If an Admin attempts to assign an inactive biker, the system **informs the Admin** (validation error: "Este entregador está inativo").
- Once assigned, if a biker is deactivated mid-shift, the `shift_bikers` record is **preserved** and the biker **remains eligible for payout** for that shift.
- The payout engine queries `shift_bikers` — it does **not** filter by `bikers.active`.

**Consequences:**
- `active` status is a guard on assignment, not on payout.
- Admin gets a clear warning when trying to assign an inactive biker.
- Deactivating a biker does not cancel their earned payouts.

---

### Decision 4: Bikers Must Have User Accounts to Be Paid

> **Answer to:** Phase 2A OQ-1
>
> Every biker who will receive payouts must have a corresponding `User` account with `role = Biker`. This enables:
- PIX key verification (BR-02) — the biker authenticates to register/verify their PIX key.
- Payment failure notifications (BR-04 / US-04) — the system can notify the biker of failures.
- Future biker dashboard (Phase 5) — the biker can view their payment history.

**Consequences:**
- The biker onboarding flow (deferred from Phase 2A) must create both a `Biker` entity and a linked `User` account.
- For MVP, Admin creates both manually. Self-registration remains deferred.
- The payout engine should verify that a `shift_bikers.biker` has a linked user before initiating payment.
- Biker entities without user accounts can exist in the system (e.g., historical data) but cannot receive automated payouts.

---

### Decision 5: Admin Must Confirm No Contested Trips Before Closing

> **Answer to:** Phase 2E Q4 (extended)
>
> When the Admin closes a shift, the system must present a **confirmation step**. The Admin must confirm that no trip count is contested before the shift transitions to `closed` and payouts are calculated.

**Consequences:**
- The `ShiftController@close` action (or a new dedicated close-review view) must display all `shift_bikers` with their final `trips_count` for Admin review.
- A new boolean or status concept of "contested" may be needed on `shift_bikers`, or the confirmation is purely a UI gate (checkbox: "Confirmo que não há viagens contestadas").
- The shift does **not** close until the Admin explicitly confirms.
- After confirmation + close, the payout engine runs.
- Contestation handling itself (RM flags a trip count, Admin resolves) is a **separate feature** — for MVP, the confirmation step is a manual checkpoint. Formal contestation workflow can be deferred.

---

## Alternatives Considered

| # | Alternative | Pros | Cons | Why Rejected |
|---|-------------|------|------|--------------|
| 1 | RM can close shifts on submission | Faster turnaround, less admin overhead | RM could close prematurely; bypasses Admin review of contested trips; breaks Admin-only close pattern from Phase 2B | Conflicts with BR-03 (Admin manual release) and the contestation checkpoint |
| 2 | Live-linked rates from Biker profile | Simpler schema, single source of truth | Retroactive rate changes corrupt historical payouts; audit trail broken; financial reports become non-reproducible | Unacceptable for a financial system |
| 3 | Allow inactive biker assignment | Flexibility for ad-hoc staffing | Admin loses visibility on who is active; no clear onboarding gate | Defeats purpose of `active` flag |
| 4 | Bikers without accounts receive payouts via Admin proxy | No onboarding friction | No audit trail per biker; PIX verification impossible; failure notifications impossible; BR-02/BR-04 unimplementable | Violates BR-02 and BR-04 requirements |
| 5 | Auto-close without confirmation | Simpler flow | No opportunity to catch errors or contestations before payouts lock | Risk of incorrect payouts with no recovery path |

---

## Consequences

### Positive

- Clear payout trigger: Admin closes → confirms → payout runs.
- Financial integrity: snapshotted rates make payouts reproducible and auditable.
- Eligibility clarity: active check at assignment, payout uses assigned records regardless of current status.
- Notification path: biker user accounts enable BR-02/BR-04.
- Quality gate: contestation confirmation prevents premature payout.

### Negative

- Admin must manually close every shift (operational overhead).
- Biker onboarding now requires account creation (slightly more setup).

### Risks

- If contestation workflow is fully deferred, Admin has no UI to flag/resolve disputes — relies on out-of-band communication for MVP.
- Biker onboarding by Admin only is a bottleneck at scale.

---

## Artefacts Affected

| Type | File | Change |
|------|------|--------|
| Controller | `app/Http/Controllers/Admin/ShiftController.php` | `close` action needs confirmation step (review + confirm) |
| View | `resources/views/admin/shifts/close-review.blade.php` | New — close review with trip counts and confirmation |
| Request | `app/Http/Requests/CloseShiftRequest.php` | May need `confirmed` boolean validation |
| Policy | `app/Policies/ShiftPolicy.php` | No change (Admin-only close already enforced) |
| Controller | `app/Http/Controllers/RestaurantManager/ShiftEntryController.php` | Remove or ignore auto-close checkbox (Decision 1) |

---

## Acceptance Criteria Covered

These decisions create preconditions for the following Phase 3 ACs (to be defined by the Planner):

- **AC-P3-D1:** Shift closing is Admin-only; no RM auto-close.
- **AC-P3-D2:** Payout reads snapshotted `base_fee` and `biker_rate` from `shift_bikers`.
- **AC-P3-D3:** Inactive bikers cannot be assigned; existing assignments preserved for payout.
- **AC-P3-D4:** Payout requires linked User account for the biker.
- **AC-P3-D5:** Shift close requires Admin confirmation that no trips are contested.

---

## References

- Plan: `docs/plans/phase-2a-auth-roles.md` (OQ-1)
- Plan: `docs/plans/phase-2c-shift-biker-assignment.md` (Q1, Q2)
- Plan: `docs/plans/phase-2e-end-of-shift-entry.md` (OQ-1, Q4)
- ADR-001: Core Payout Schema
- ADR-002: Auth & Roles
- ADR-004: Shift-Biker Assignment

---

_See [ADR Index](./README.md) for all decisions._
