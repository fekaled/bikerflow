# BikerFlow — Project Progress Board

> **Last Updated:** 2026-05-15 (Phase 2E End-of-Shift Entry — 🟢 Validated)
> **Current Phase:** Phase 2E — End-of-Shift Entry (Validated)

---

## Phase Overview

| Phase | Description | Status |
|-------|-------------|--------|
| **Phase 1** | Foundation — Auth, core models, database schema | 🟢 Validated |
| **Phase 2A** | Auth & Roles — User authentication, RBAC, magic link | 🟢 Validated |
| **Phase 2B** | Shift CRUD & Lifecycle — Admin shift management | 🟢 Validated* (ADR-003) |
| **Phase 2C** | Shift-Biker Assignment — Admin biker management on shifts | 🟢 Validated (ADR-004) |
| **Phase 2D** | Live Tick Tracking — Restaurant Manager real-time trip counting | 🟢 Validated |
| **Phase 2E** | End-of-Shift Entry — Restaurant Manager manual trip count entry | 🟢 Validated |
| **Phase 3** | Payout Engine — Calculations, margin, financial precision | 🔵 Not Started |
| **Phase 4** | Payment Integration — PIX release, retries, granular failure | 🔵 Not Started |
| **Phase 5** | Dashboards & Notifications — Admin margin, biker status | 🔵 Not Started |

---

## User Stories

| ID | Story | Status | Plan | Tests (RED) | Tests (GREEN) | Audit |
|----|-------|--------|------|-------------|---------------|-------|
| Phase-1 | Core Schema & Payout Formula | 🟢 Validated | `docs/plans/phase-1-core-schema-payout.md` | 10 test files | ✅ 205 pass, 365 assertions, 0 regressions | `docs/audits/phase-1-core-schema-audit.md` |
| Phase-2C | Shift-Biker Assignment (Admin) | 🟢 Validated | `docs/plans/phase-2c-shift-biker-assignment.md` | `ShiftBikerControllerTest` (47 tests) | ✅ All pass, 0 regressions | ADR-004 |
| Phase-2B | Shift CRUD & Lifecycle (Admin) | 🟢 Validated* (ADR-003) | `docs/plans/phase-2b-shift-crud-lifecycle.md` | `ShiftControllerTest` (74 tests) | ✅ 407 pass, 676 assertions, 0 regressions | `docs/audits/phase-2b-shift-crud-lifecycle-audit.md` |
| Phase-2A | Auth & Roles: Magic Link + RBAC | 🟢 Validated | `docs/plans/phase-2a-auth-roles.md` | 5 test files (UserRoleEnumTest, MagicLinkTest, RoleMiddlewareTest, GatesPoliciesTest, UserModelTest) | ✅ All pass, 0 regressions | ADR-002 + 205 existing tests still green |
| Phase-2D | Live Tick Tracking (Restaurant Manager) | 🟢 Validated | `docs/plans/phase-2d-live-tick-tracking.md` | `tests/Feature/Controllers/ShiftTrackingControllerTest.php` (57 tests) | ✅ All pass, 0 regressions | Phase 2D audit |
| Phase-2E | End-of-Shift Entry (Restaurant Manager) | 🟢 Validated | `docs/plans/phase-2e-end-of-shift-entry.md` | `tests/Feature/Controllers/ShiftEntryControllerTest.php` (56 tests) | ✅ All pass, 0 regressions | BR-01 enforced at 3 layers |
| US-01 | PDF Trip Sheet for manual tracking | 🔵 Not Started | — | — | — | — |
| US-02 | Holiday shift rate override | 🔵 Not Started | — | — | — | — |
| US-03 | Admin Margin Dashboard | 🔵 Not Started | — | — | — | — |
| US-04 | Biker PIX failure notification | 🔵 Not Started | — | — | — | — |

---

## Business Rules

| ID | Rule | Status | Enforced In | Verified By |
|----|------|--------|-------------|-------------|
| BR-01 | Workflow Locking | 🟢 Validated | `app/Models/Shift.php` (boot saving hook) + `TickTripRequest` (BR-01 live_tick guard) + `SubmitTripsRequest` (BR-01 manual_entry guard) | Phase-1 audit (AC-36→AC-38a), Phase-2D, Phase-2E (AC-2E-11, AC-2E-12) |
| BR-02 | PIX Verification | 🟡 Partial | Schema: `pix_keys` table (is_verified, verified_at) | Phase-1 audit (schema only, API deferred) |
| BR-03 | Manual Release (Payout Formula) | 🟢 Validated | `app/Services/PayoutService.php` + `app/Services/RevenueService.php` | Phase-1 audit + BR-03 audit |
| BR-04 | Granular Payment Failure | 🟡 Partial | Schema: payment per shift_biker, independent status | Phase-1 audit (schema only, controller deferred) |
| BR-05 | Last Minute Biker (Admin Only) | 🟢 Validated | `app/Policies/ShiftPolicy.php` (addBiker), `app/Providers/AppServiceProvider.php` (manage-shift-bikers gate), `app/Http/Controllers/Admin/ShiftBikerController.php`, `app/Http/Requests/AssignBikerRequest.php` | Phase-2A (AC-30, AC-34), Phase-2C (AC-2C-01→AC-2C-07, AC-2C-32→AC-2C-38) |
| BR-06 | Payment Retry Audit Logging | 🟢 Validated | Schema: `payment_audit_logs.transaction_ref` UNIQUE | Phase-1 audit (AC-08, BR-06) |

---

## Core Entities

| Entity | Migration | Model | Controller | Routes | Tests | Status |
|--------|-----------|-------|------------|--------|-------|--------|
| Restaurant | ✅ `2026_05_14_000001` | ✅ `app/Models/Restaurant.php` | — | — | `RestaurantModelTest`, `FactoryTest`, `PayoutIntegrationTest` | 🟢 Validated |
| Biker | ✅ `2026_05_14_000002` | ✅ `app/Models/Biker.php` | — | — | `BikerModelTest`, `FactoryTest`, `PayoutIntegrationTest` | 🟢 Validated |
| Shift | ✅ `2026_05_14_000003` | ✅ `app/Models/Shift.php` | ✅ `app/Http/Controllers/Admin/ShiftController.php` | ✅ `routes/web.php` (admin-only resource + close) | `ShiftModelTest`, `FactoryTest`, `ShiftControllerTest` | 🟢 Validated |
| StoreShiftRequest | — | — | ✅ `app/Http/Requests/StoreShiftRequest.php` | — | `ShiftControllerTest` | 🟢 Validated |
| UpdateShiftRequest | — | — | ✅ `app/Http/Requests/UpdateShiftRequest.php` | — | `ShiftControllerTest` | 🟢 Validated |
| CloseShiftRequest | — | — | ✅ `app/Http/Requests/CloseShiftRequest.php` | — | `ShiftControllerTest` | 🟢 Validated |
| ShiftBiker | ✅ `2026_05_14_000004` | ✅ `app/Models/ShiftBiker.php` | ✅ `app/Http/Controllers/Admin/ShiftBikerController.php` | ✅ `routes/web.php` (nested under shifts, admin-only) | `ShiftBikerModelTest`, `FactoryTest`, `PayoutIntegrationTest`, `ShiftBikerControllerTest` | 🟢 Validated |
| AssignBikerRequest | — | — | ✅ `app/Http/Requests/AssignBikerRequest.php` | — | `ShiftBikerControllerTest` | 🟢 Validated |
| UpdateShiftBikerRequest | — | — | ✅ `app/Http/Requests/UpdateShiftBikerRequest.php` | — | `ShiftBikerControllerTest` | 🟢 Validated |
| PixKey | ✅ `2026_05_14_000005` | ✅ `app/Models/PixKey.php` | — | — | `PixKeyModelTest`, `FactoryTest` | 🟢 Validated |
| Payment | ✅ `2026_05_14_000006` | ✅ `app/Models/Payment.php` | — | — | `PaymentModelTest`, `FactoryTest` | 🟢 Validated |
| PaymentAuditLog | ✅ `2026_05_14_000007` | ✅ `app/Models/PaymentAuditLog.php` | — | — | `PaymentAuditLogModelTest`, `FactoryTest` | 🟢 Validated |
| User (auth) | ✅ `2026_05_14_000008` (alter), `2026_05_14_000009` (FK) | ✅ `app/Models/User.php` | ✅ `app/Http/Controllers/Auth/MagicLinkController.php` | ✅ `routes/web.php` (auth routes) | `UserModelTest`, `MagicLinkTest`, `RoleMiddlewareTest`, `GatesPoliciesTest`, `UserRoleEnumTest` | 🟢 Validated |
| UserRole | ✅ `app/Enums/UserRole.php` | — | — | — | `UserRoleEnumTest` | 🟢 Validated |
| ShiftTrackingController | — | — | ✅ `app/Http/Controllers/RestaurantManager/ShiftTrackingController.php` | ✅ `routes/web.php` | `ShiftTrackingControllerTest` | 🟢 Validated |
| TickTripRequest | — | — | ✅ `app/Http/Requests/TickTripRequest.php` | — | `ShiftTrackingControllerTest` | 🟢 Validated |
| Tracking Dashboard View | — | — | — | ✅ `resources/views/tracking/dashboard.blade.php` | `ShiftTrackingControllerTest` | 🟢 Validated |
| ShiftEntryController | — | — | ✅ `app/Http/Controllers/RestaurantManager/ShiftEntryController.php` | ✅ `routes/web.php` (`entry.show`, `entry.store`) | `ShiftEntryControllerTest` | 🟢 Validated |
| SubmitTripsRequest | — | — | ✅ `app/Http/Requests/SubmitTripsRequest.php` | — | `ShiftEntryControllerTest` | 🟢 Validated |
| Entry Form View | — | — | — | ✅ `resources/views/entry/show.blade.php` | `ShiftEntryControllerTest` | 🟢 Validated |
| ShiftPolicy | — | — | — | — | `GatesPoliciesTest`, `ShiftControllerTest` | 🟢 Validated |
| RestaurantPolicy | — | — | — | — | `GatesPoliciesTest` | 🟢 Validated |
| BikerPolicy | — | — | — | — | `GatesPoliciesTest` | 🟢 Validated |

---

## Infrastructure

| Component | Status | Notes |
|-----------|--------|-------|
| Dev Container | ✅ Done | Docker Compose operational |
| Database (MySQL 8.4) | ✅ Done | Running, accessible |
| Laravel 13 installed | ✅ Done | Framework bootstrapped |
| PHPUnit configured | ✅ Done | SQLite in-memory, phpunit.xml ready |
| Auth (WhatsApp Magic Link) | 🟢 Validated | Laravel Breeze + MagicLinkController + EnsureUserRole middleware + Gates + Policies. 3 roles: Admin, RestaurantManager, Biker. Phone-based login with signed URLs. |
| BCMath configured | 🟢 Validated | `app/Services/PayoutService.php` uses BCMath scale 2 for all arithmetic |
| Snapshot/Rollback scripts | ✅ Done | `bin/agent-jail/` operational |

---

## TDD Pipeline

The standard flow for every feature:

```
/plan <task>        →  Planner produces blueprint     →  🟡 Planned
                          ↓
/test <task> red    →  Tester writes failing tests     →  🟥 Tests RED
                          ↓
/develop <plan>     →  Developer implements code        →  🟠 In Development
                          ↓
/test <task> green  →  Tester confirms tests pass       →  🟩 Tests GREEN
                          ↓
/validate <task>    →  Validator audits                 →  🟢 Validated
                          ↓
merge to main       →  Orchestrator merges              →  ✅ Done
```

> **TDD Rule:** No code is written before a failing test describes it. No feature is complete until a passing test proves it.

---

## Status Legend

| Icon | Status | Meaning |
|------|--------|---------|
| 🔵 | Not Started | No work has begun |
| 🟡 | Planned | Planner has produced a blueprint in `docs/plans/` |
| 🟥 | Tests RED | Tester has written failing tests (TDD — expected) |
| 🟠 | In Development | Developer is writing code to make tests pass |
| 🟩 | Tests GREEN | All tests pass, no regressions |
| 🟣 | In Validation | Validator is auditing the implementation |
| 🔴 | Blocked | Needs user decision or external input |
| 🟢 | Validated | Validator has audited and approved |
| ✅ | Done | Merged to `main`, production-ready |

---

## Agent Activity Log

<!-- Newest entries at the top -->

| Date | Agent | Action | Details |
|------|-------|--------|---------|
| 2026-05-15 | Tracker | Updated progress for Phase 2E — End-of-Shift Entry | Pipeline complete — 🟢 Validated. Deliverables: ShiftEntryController (show, store), SubmitTripsRequest (BR-01 manual_entry guard, shift open check, biker assignment check, non-negative integer validation), ShiftPolicy@submitTrips, web routes (`entry.show`, `entry.store` protected by auth + role:restaurant_manager,admin), Blade view (`entry/show.blade.php`), tracking dashboard integration ("Registrar Viagens" link for manual_entry shifts). 56 tests covering AC-2E-01 through AC-2E-33. BR-01 enforced at 3 layers (model boot hook, SubmitTripsRequest withValidator, ShiftPolicy). No new migrations. Phase 2D also validated (57 tests). All existing 543+ tests pass. Next: Phase 3 — Payout Engine. |
| 2026-05-15 | Tracker | Updated progress for Phase 2D — Live Tick Tracking | Planner produced blueprint at `docs/plans/phase-2d-live-tick-tracking.md`. Tester wrote 57 failing tests at `tests/Feature/Controllers/ShiftTrackingControllerTest.php` (all RED — TDD RED phase). Covers AC-2D-01 through AC-2D-32: routes, authorization, BR-01 enforcement (live_tick workflow guard), tick execution, dashboard view, navigation. No new migrations. Next: Developer implements ShiftTrackingController, TickTripRequest, Blade view, routes. All 482 existing tests must remain green. |
| 2026-05-14 | Tracker | Finalized Phase 2C pipeline — created ADR-004 | Created `docs/adr/004-shift-biker-assignment.md` (Shift-Biker Assignment). Updated ADR index. Phase 2C: 🟢 Validated. Deliverables: ShiftBikerController (4 actions), AssignBikerRequest, UpdateShiftBikerRequest, nested routes, Blade partial. BR-01 enforced at 2 layers, BR-05 at 3 layers. 47 test methods, all existing 407+ tests still green. No new migrations. Next: Phase 3 — Payout Engine. |
| 2026-05-14 | Tracker | Finalized Phase 2B pipeline — created ADR-003 | Created `docs/adr/003-shift-crud-lifecycle.md` (Shift CRUD & Lifecycle). Updated ADR index (ADR-003, ADR-004 reserved). Phase 2B: 🟢 Validated* with 4 known findings (M-01 dashboard nav gap, M-02 numeric vs decimal:0,2, L-01 English text, L-02 Blade inline query). 74 tests, 407 total suite, 0 regressions. Next: Phase 3 — Payout Engine. |
| 2026-05-14 | Validator | Audited Phase 2B Shift CRUD & Lifecycle — 🟢 PASS WITH CONDITIONS | 74 tests, 134 assertions. All 47 ACs met. BR-01 enforced at 3 layers. BR-05 enforced at 2 layers. 4 findings: M-01 dashboard missing Turnos link, M-02 validation rule deviation (numeric vs decimal:0,2), L-01 English text in PT-BR UI, L-02 inline User::find in Blade. Audit: `docs/audits/phase-2b-shift-crud-lifecycle-audit.md`. |
| 2026-05-14 | Tracker | Updated progress for Phase 2A — Auth & Roles | Phase-2A status: 🟢 Validated. Created ADR-002 (`docs/adr/002-auth-roles-magic-link.md`). Updated ADR index. Added User/UserRole/Policy entities to Core Entities table. Updated BR-05 to 🟢 Validated. Updated Auth infrastructure to 🟢 Validated. Added Phase-2A row to Phase Overview. 5 new test files, 46 ACs covered. |
| 2026-05-14 | Tracker (manual) | Created ADR mechanism + ADR-001 | Created `docs/adr/` with README index, TEMPLATE.md, and ADR-001 (core payout schema). Updated tracker skill to automate ADR creation on future pipelines. Archived pipeline manifest to `docs/archives/pipelines/`. Added ADR cross-references to Shift model, ShiftStatus/PaymentStatus enums, plan file, and audit report. |
| 2026-05-14 | Validator | Audited Phase-1 Core Schema — 🟢 PASS WITH CONDITIONS → fixed | 205 tests, 365 assertions. All 41 ACs met. 3 findings: (1) Restaurant `$guarded = []` removed — fixed, (2) AC numbering collision in plan — cosmetic, (3) plan scope says 6 enums but only 5 exist — cosmetic. Audit: `docs/audits/phase-1-core-schema-audit.md`. Verdict: PASS after fix. |
| 2026-05-14 | Tracker | Updated progress board | Phase-1 Core Schema status: 🟢 Validated. All 7 entities validated. BR-01, BR-03, BR-06 fully enforced. BR-02, BR-04, BR-05 partial (schema only). 205 tests green. |
| 2026-05-14 | Developer (Phase 1) | Implemented Phase-1 Core Schema | 30 files: 7 migrations, 7 models, 5 enums, 2 services (PayoutService preserved, RevenueService new), 7 factories, WorkflowLockedException. All 205 tests green. |
| 2026-05-14 | Tester (Phase 1) | Tests GREEN — 205/205 pass | 10 test files: PayoutServiceTest (30), RevenueServiceTest (14), EnumTest (23), RestaurantModelTest (5), BikerModelTest (7), ShiftModelTest (30), ShiftBikerModelTest (6), PixKeyModelTest (11), PaymentModelTest (13), PaymentAuditLogModelTest (12), FactoryTest (26), PayoutIntegrationTest (15). |
| 2026-05-14 | Tester (Phase 1) | Tests RED — 130 failing tests written | 10 test files covering all 41 ACs. All fail prior to implementation. |
| 2026-05-13 | Planner | Produced blueprint | Phase 1 plan: 7 tables, 7 models, 2 services, 6 enums, 41 ACs. Complexity: Complex. 3 open questions flagged. |
| 2026-05-14 | Tracker | Verified Phase-1 plan | Plan at `docs/plans/phase-1-core-schema-payout.md` confirmed present. Summary: 7 tables (restaurants, bikers, pix_keys, shifts, shift_bikers, payments, payment_audit_logs), 7 Eloquent models with relationships, 6 backed enums, 2 BCMath financial services (PayoutService, RevenueService), 7 factories, 41 acceptance criteria. 3 open questions flagged (auth FK columns, trip-level granularity, shift draft state). BR-01 (workflow locking), BR-03 (payout formula), BR-04 (granular failure), BR-06 (payment retry audit) all addressed in plan. |
| 2026-05-14 | Validator | Audited BR-03 — 🟢 Validated | All 18 tests pass. BCMath scale 2 enforced. Formula matches PRD/BR-03 exactly. No floats in financial path. Negative trips rejection verified. Return type string verified. Decimal precision verified at DECIMAL(12,2) boundary. |
| 2026-05-14 | Tester | Confirmed GREEN — 18/18 tests pass | `tests/Unit/PayoutServiceTest.php`: 18 tests (AC-05 through AC-16 + AC-29 + boundary tests), 0 failures, 0 regressions. BCMath precision verified. |
| 2026-05-14 | Developer | Implemented PayoutService | `app/Services/PayoutService.php` — `calculate(baseFee, bikerRate, tripsCount): string`. BCMath bcmul/bcadd with scale 2. Zero-trip guard. InvalidArgumentException for negative trips. |
| 2026-05-14 | Tester | Tests RED — 18 failing tests written | `tests/Unit/PayoutServiceTest.php` — 18 unit tests covering AC-05→AC-16, AC-29, boundary tests. All fail (PayoutService does not exist yet). |
| 2026-05-14 | Planner | Produced BR-03 blueprint | `docs/plans/BR-03-payout-formula.md` — PayoutService with calculate(), 18+ acceptance criteria, BCMath scale 2, migration for shift_bikers inputs. |
| 2026-05-13 | Orchestrator | Created progress board | Initial setup with TDD pipeline |
