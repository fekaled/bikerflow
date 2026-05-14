# ADR-002: Auth & Roles — Phone-Based Magic Link Authentication with Role-Based Authorization

**Date:** 2026-05-14
**Status:** Accepted
**Decision Maker:** Planner (blueprint), Developer (implementation), Validator (audit)
**Task ID:** phase-2a
**Pipeline:** full-tdd-phase-2a-auth-roles
**Business Rules:** BR-03, BR-05
**Related Plan:** `docs/plans/phase-2a-auth-roles.md`

---

## Context

BikerFlow requires an authentication and authorization layer to support three distinct personas: Admin (Company Manager), Restaurant Manager, and Biker. The PRD specifies phone-based authentication via WhatsApp Magic Link — users enter their phone number, receive a signed URL via WhatsApp, and click to authenticate.

Several key decisions were needed:

1. **Auth mechanism** — Password-based vs. magic link vs. OTP. The PRD mandates WhatsApp Magic Link.
2. **Role storage** — Separate roles table/permissions vs. simple enum column on users.
3. **Entity linkage** — How Users relate to existing Restaurant and Biker entities.
4. **Breeze integration** — Use Laravel Breeze for scaffolding but replace password-based login with magic link.

---

## Decision

### Three-Role Enum on Users Table

A `UserRole` backed string enum (`Admin`, `RestaurantManager`, `Biker`) stored as a `role` column on the `users` table. No separate roles/permissions tables — the three roles are fixed by the PRD and don't require dynamic assignment.

### Phone-Based Magic Link Auth

- **Login flow:** User enters phone → system generates `URL::temporarySignedRoute` (15-min expiry) → dispatched via `WhatsappServiceInterface` (log fake for dev) → user clicks → signature verified → authenticated.
- **No passwords** — `email` and `password` columns made nullable.
- **User enumeration prevention** — Same success message regardless of whether phone is registered.
- **Session fixation prevention** — `session()->regenerate()` on successful auth.

### Entity Linkage via Nullable FKs

- `users.restaurant_id` → `restaurants.id` (nullable, for RestaurantManager role)
- `users.biker_id` → `bikers.id` (nullable, for Biker role)
- `shifts.created_by` → `users.id` (FK added, ON DELETE SET NULL)

### Authorization: Gates + Policies + Middleware

- **Gates** registered in `AppServiceProvider`: `admin`, `restaurant-manager`, `biker`, `release-payment` (BR-03), `manage-shift-bikers` (BR-05).
- **Policies** for Shift, Restaurant, Biker models — Admin has full access, RestaurantManager is scoped to their own restaurant, Biker has read-only access.
- **`EnsureUserRole` middleware** for route-level role enforcement (`role:admin`, `role:restaurant_manager`, `role:biker`).

### WhatsApp Service Interface

- `WhatsappServiceInterface` with `sendMagicLink()` and `sendMessage()` methods.
- `WhatsappLogService` as the dev implementation (writes to Laravel log).
- Bound in the service container for easy replacement with real WhatsApp API integration later.

---

## Alternatives Considered

| # | Alternative | Pros | Cons | Why Rejected |
|---|-------------|------|------|--------------|
| 1 | **Laravel Sanctum (API tokens)** | Standard API auth, SPA support | PRD specifies session-based auth with WhatsApp magic link; no API token requirement | Not needed for session-based web app |
| 2 | **Laravel Fortify** | Headless auth backend | Too opinionated; Breeze Blade stack matches our Blade + Vite frontend better | Breeze provides better Blade integration |
| 3 | **Separate roles/permissions tables** (SPatie) | Flexible, dynamic role assignment | Over-engineering for 3 fixed roles defined in PRD; adds complexity | Simple enum column is sufficient and more performant |
| 4 | **OTP-based auth** (SMS code) | Familiar UX pattern | PRD specifically mandates magic link via WhatsApp; OTP would be a different flow | PRD requirement is magic link |
| 5 | **Multi-restaurant managers** (pivot table) | Supports managers overseeing multiple locations | PRD implies 1:1; adds schema complexity | Start simple; pivot can be added later if needed |

---

## Consequences

### Positive

- **Frictionless auth** — No passwords to remember; one-click login via WhatsApp.
- **PRD-aligned roles** — Three fixed roles match the three personas exactly.
- **Policy-based access control** — Fine-grained authorization at model level, easy to test.
- **Entity linkage** — User ↔ Restaurant/Biker relationships enable scoped queries.
- **BR-03 and BR-05 enforced** — Admin-only gates for payment release and shift biker management.
- **Testable** — All auth logic (gates, policies, middleware) testable without HTTP in many cases.

### Negative

- **WhatsApp dependency** — Production requires Twilio/Meta API integration (deferred).
- **Magic link replay** — Signed URLs are technically reusable within expiry window; no server-side token tracking for MVP.
- **No self-registration** — Admin must create all user accounts; no public registration endpoint yet.
- **Single restaurant per manager** — 1:1 constraint; multi-restaurant support would require schema change.

### Risks

- **Phone number format inconsistency** — Must normalize to E.164 before lookup; mutator needed on User model.
- **Breeze scaffolding overhead** — Many unused Breeze views/routes created; must be pruned or ignored.
- **Email nullable impact** — Existing Breeze validation assumes email is required; disabled unused Breeze routes to mitigate.

---

## Artefacts Affected

| Type | File | Change |
|------|------|--------|
| Migration | `database/migrations/2026_05_14_000008_add_role_and_phone_to_users_table.php` | Created |
| Migration | `database/migrations/2026_05_14_000009_add_foreign_key_to_shifts_created_by.php` | Created |
| Enum | `app/Enums/UserRole.php` | Created |
| Model | `app/Models/User.php` | Modified (role cast, relationships, helper methods) |
| Controller | `app/Http/Controllers/Auth/MagicLinkController.php` | Created |
| Middleware | `app/Http/Middleware/EnsureUserRole.php` | Created |
| Policy | `app/Policies/ShiftPolicy.php` | Created |
| Policy | `app/Policies/RestaurantPolicy.php` | Created |
| Policy | `app/Policies/BikerPolicy.php` | Created |
| Contract | `app/Contracts/WhatsappServiceInterface.php` | Created |
| Service | `app/Services/WhatsappLogService.php` | Created |
| Provider | `app/Providers/AppServiceProvider.php` | Modified (gates registration) |
| Bootstrap | `bootstrap/app.php` | Modified (middleware aliases) |
| Routes | `routes/web.php` | Modified (auth routes) |
| Factory | `database/factories/UserFactory.php` | Modified (role states) |
| View | `resources/views/auth/login.blade.php` | Created (phone input) |
| View | `resources/views/auth/magic-link-sent.blade.php` | Created |
| View | `resources/views/auth/verify.blade.php` | Created |
| View | `resources/views/dashboard.blade.php` | Created |
| Config | `config/auth.php` | Modified (magic_link lifetime) |
| Test | `tests/Unit/Enums/UserRoleEnumTest.php` | Created |
| Test | `tests/Feature/Auth/MagicLinkTest.php` | Created |
| Test | `tests/Feature/Auth/RoleMiddlewareTest.php` | Created |
| Test | `tests/Feature/Auth/GatesPoliciesTest.php` | Created |
| Test | `tests/Feature/Models/UserModelTest.php` | Created |

---

## Acceptance Criteria Covered

All 46 acceptance criteria (AC-01 through AC-46) covering:
- User schema changes (AC-01 to AC-08)
- UserRole enum (AC-09 to AC-10)
- User model (AC-11 to AC-16)
- Magic link auth flow (AC-17 to AC-25)
- Gates (AC-26 to AC-30)
- Policies (AC-31 to AC-35)
- Middleware (AC-36 to AC-39)
- Session configuration (AC-40 to AC-41)
- Regression (AC-42 to AC-43)
- Service interface (AC-44 to AC-46)

---

## References

- Plan: `docs/plans/phase-2a-auth-roles.md`
- PRD Section 2: User Personas & Main Flows
- PRD Section 4: Business Rules (BR-03, BR-05)
- Tech Doc Section 1: Primary Tech Stack (Auth: WhatsApp Magic Link)
- Tech Doc Section 4B: Authentication Flow (WhatsApp Magic Link)
- ADR-001: Core Payout Schema (preceding foundation)

---

_See [ADR Index](./README.md) for all decisions._
