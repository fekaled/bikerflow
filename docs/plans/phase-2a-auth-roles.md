# Plan: Phase 2A — Auth & Roles (User Authentication & Role-Based Authorization)

**Task ID:** phase-2a
**Date:** 2026-05-14
**Planner Version:** 1.0
**Complexity:** Complex

---

## 1. Objective

Implement the complete authentication and role-based authorization layer for BikerFlow. This plan delivers phone-based magic link authentication (WhatsApp integration deferred), a three-role system (Admin, RestaurantManager, Biker), role-based gates and policies, and the linkage between User identities and existing domain entities (Restaurant, Biker). All 205 existing tests must continue to pass with zero regressions.

---

## 2. Source References

### User Stories
- No direct US-XX mapping. This is foundational infrastructure enabling US-01 through US-04 and all persona-based access control.

### Business Rules
- **BR-05** Last Minute Biker — "Only the Admin can add/replace bikers once a shift has been initiated." Requires role-based authorization to enforce.
- **BR-03** Manual Release — "No automated payments occur without explicit Admin 'Approval' per shift." Admin role gate must guard this action.

### PRD Sections
- Section 2: User Personas & Main Flows — Defines three personas (Restaurant Manager, Biker, Company Manager) with distinct access patterns.
- Section 4: Business Rules — BR-05 specifically requires Admin-only operations.

### Tech Doc Sections
- Section 1: Primary Tech Stack — "Auth: WhatsApp Magic Link — Frictionless onboarding via Laravel Breeze."
- Section 4B: Authentication Flow (WhatsApp Magic Link) — Defines the phone → signed URL → WhatsApp → click → authenticated flow.
- Section 5: Security & Guardrails — General security posture.

---

## 3. Scope

### In Scope

1. **Users table modification** — Add `role`, `phone`, `phone_verified_at`, `restaurant_id`, `biker_id` columns. Make `email` and `password` nullable (phone-based auth).
2. **UserRole backed enum** — `Admin`, `RestaurantManager`, `Biker` with string values.
3. **User Eloquent model** — Role casting, entity relationships (restaurant, biker), helper methods (`isAdmin()`, `isRestaurantManager()`, `isBiker()`).
4. **Laravel Breeze installation** — Blade stack (matching existing Blade + Vite + Tailwind frontend). Install, then customize for phone-based login.
5. **Magic Link Auth System** — `MagicLinkController` with request-link and verify-link actions. Signed URL generation (15-minute expiry). Service interface for WhatsApp message dispatch (implementation deferred).
6. **Role-based authorization** — Gates (`admin`, `restaurant-manager`, `biker`) registered in `AppServiceProvider`. Policies for Shift (`view`, `create`, `update`, `delete`), Restaurant, Biker entity access.
7. **Middleware registration** — `auth`, `verified` middleware aliases registered in `bootstrap/app.php`. Role middleware (`role:<role>`) as custom middleware.
8. **UserFactory update** — Support all three roles with entity associations.
9. **Session configuration** — Verify database driver is active (already configured).
10. **Foreign key constraint** — Add `shifts.created_by` → `users.id` foreign key.
11. **Auth views** — Login (phone input), magic link sent confirmation, verification/landing page.

### Out of Scope

1. **WhatsApp API integration** — Actual message sending via Twilio/Meta API. The `WhatsappService` is defined as an interface only; a log-based fake is used for development.
2. **Biker self-registration** — PRD mentions "secure link" onboarding. Deferred to Phase 2B/3.
3. **Registration flow for new users** — Admin creates all user accounts via a future admin panel. No public registration endpoint.
4. **Email verification flow** — Phone is the primary identifier; email verification is not used.
5. **Password-based login** — Replaced entirely by magic link; Breeze default password routes removed.
6. **API token authentication** — Session-only auth per requirements. No Sanctum/Passport.
7. **Two-factor authentication** — Not required for MVP.
8. **User CRUD admin panel** — Views for managing users. Deferred to admin dashboard phase.
9. **Biker read-only dashboard** — Deferred to Phase 5 (Dashboards).

### Open Questions

1. **OQ-1: Should a Biker entity always have a corresponding User?** Currently, the `bikers` table exists independently. This plan assumes Users are created separately and linked to Biker entities via `users.biker_id`. Not all bikers need user accounts initially (some may be managed by Admin only). **Recommendation:** Users with `role=Biker` are optional — a biker entity can exist without a user account. If the user wants all bikers to always have accounts, this needs confirmation.

2. **OQ-2: Should a Restaurant Manager user be restricted to a single restaurant?** This plan assumes 1:1 (one user manages one restaurant). If multi-restaurant managers are needed, a pivot table would be required. **Recommendation:** Start with 1:1 per PRD simplicity.

3. **OQ-3: Magic link lifetime** — Tech doc says 15 minutes. Is this appropriate for WhatsApp delivery delays? **Recommendation:** Keep 15 minutes as default, configurable via `config/auth.php`.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | Indirect | Auth enables `created_by` on shifts to track who initiated. Workflow locking itself is already implemented. |
| BR-02 PIX Verification | Indirect | Admin role gate will guard the PIX verification action (future controller). No direct implementation in this phase. |
| BR-03 Manual Release | Yes | Admin role gate must guard payment release actions. `Gate::define('release-payment', ...)` requires Admin role. |
| BR-04 Granular Failure | No | Payment-level independence already in schema. No auth dependency. |
| BR-05 Last Minute Biker | Yes | Only Admin can add/replace bikers on an initiated shift. `ShiftPolicy::addBiker()` must check `user->isAdmin()`. |
| BR-06 Payment Retries | Indirect | Admin role gate will guard retry actions (future controller). |

---

## 5. Schema Changes

### New Tables

No new tables. The `sessions` and `password_reset_tokens` tables already exist from the default Laravel migration.

### Modified Tables

```
users
├── + phone                   VARCHAR(20) NULLABLE UNIQUE    — Primary auth identifier (WhatsApp number)
├── + phone_verified_at       TIMESTAMP NULLABLE              — When phone was verified via magic link
├── + role                    VARCHAR(30) NOT NULL DEFAULT 'admin'  — UserRole enum value
├── + restaurant_id           BIGINT UNSIGNED NULLABLE        — FK to restaurants (for RestaurantManager role)
├── + biker_id                BIGINT UNSIGNED NULLABLE        — FK to bikers (for Biker role)
├── ~ email                   VARCHAR(255) → NULLABLE         — No longer required for phone-based auth
├── ~ password                VARCHAR(255) → NULLABLE         — Not used for magic link auth
├── ~ email_verified_at       TIMESTAMP → NULLABLE (already nullable, keep as-is)
└── timestamps

shifts
├── ~ created_by              Add FK constraint → users.id  (currently unsignedBigInteger nullable, no FK)
└── timestamps
```

### Indexes

- `users_phone_unique` on `users(phone)` — Fast lookup by phone number during magic link auth.
- `users_role_index` on `users(role)` — Filtering users by role.
- `users_restaurant_id_foreign` on `users(restaurant_id)` — FK index.
- `users_biker_id_foreign` on `users(biker_id)` — FK index.

### Foreign Keys

- `users.restaurant_id` → `restaurants.id` ON DELETE SET NULL
- `users.biker_id` → `bikers.id` ON DELETE SET NULL
- `shifts.created_by` → `users.id` ON DELETE SET NULL

### Financial Column Checklist

N/A — No financial columns in this phase.

---

## 6. Affected Files

### Create

| Layer | File Path | Purpose |
|-------|-----------|---------|
| Enum | `app/Enums/UserRole.php` | Backed string enum: Admin, RestaurantManager, Biker |
| Migration | `database/migrations/2026_05_14_000008_add_role_and_phone_to_users_table.php` | Add role, phone, phone_verified_at, restaurant_id, biker_id; alter email/password to nullable |
| Migration | `database/migrations/2026_05_14_000009_add_foreign_key_to_shifts_created_by.php` | Add FK constraint shifts.created_by → users.id |
| Controller | `app/Http/Controllers/Auth/MagicLinkController.php` | Request magic link (show form + send) and verify magic link (consume + authenticate) |
| Service Interface | `app/Contracts/WhatsappServiceInterface.php` | Interface for WhatsApp message dispatch (log fake for dev) |
| Service | `app/Services/WhatsappLogService.php` | Log-based fake implementation of WhatsappServiceInterface |
| Middleware | `app/Http/Middleware/EnsureUserRole.php` | Role-checking middleware `role:admin`, `role:restaurant-manager`, `role:biker` |
| Policy | `app/Policies/ShiftPolicy.php` | Authorization for shift CRUD: Admin=all, RestaurantManager=own restaurant only, Biker=read-only |
| Policy | `app/Policies/RestaurantPolicy.php` | Authorization: Admin=all, RestaurantManager=view own, Biker=read-only |
| Policy | `app/Policies/BikerPolicy.php` | Authorization: Admin=all, Biker=view own profile, RestaurantManager=read-only |
| View | `resources/views/auth/login.blade.php` | Phone number input form (replaces Breeze default email/password) |
| View | `resources/views/auth/magic-link-sent.blade.php` | Confirmation that magic link was sent |
| View | `resources/views/auth/verify.blade.php` | Magic link verification landing page |
| View | `resources/views/layouts/app.blade.php` | Authenticated layout with nav (Breeze scaffold base) |
| View | `resources/views/dashboard.blade.php` | Simple post-login dashboard placeholder |
| Test | `tests/Unit/Enums/UserRoleEnumTest.php` | Unit tests for UserRole enum |
| Test | `tests/Feature/Auth/MagicLinkTest.php` | Feature tests for magic link request and verification flow |
| Test | `tests/Feature/Auth/RoleMiddlewareTest.php` | Feature tests for role middleware |
| Test | `tests/Feature/Auth/GatesPoliciesTest.php` | Feature tests for gates and policies |
| Test | `tests/Feature/Models/UserModelTest.php` | Feature tests for User model (role casting, relationships, helpers) |

### Modify

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Model | `app/Models/User.php` | Add role cast (UserRole enum), phone/phone_verified_at fillable, entity relationships (restaurant(), biker()), helper methods (isAdmin(), isRestaurantManager(), isBiker()) |
| Factory | `database/factories/UserFactory.php` | Add phone, role generation; add state methods for each role (admin(), restaurantManager($restaurant), biker($biker)) |
| Provider | `app/Providers/AppServiceProvider.php` | Register Gates (admin, restaurant-manager, biker, release-payment, manage-shift-bikers) in boot() |
| Bootstrap | `bootstrap/app.php` | Register middleware aliases (role → EnsureUserRole), configure auth middleware groups |
| Routes | `routes/web.php` | Add auth routes: magic link request (GET/POST), magic link verify (GET), dashboard (GET), logout (POST) |
| Seeder | `database/Seeders/DatabaseSeeder.php` | Add seed for admin user with phone number |
| Config | `config/auth.php` | Add magic_link lifetime config (15 minutes) |
| Composer | `composer.json` | Add `laravel/breeze` to require-dev (dev dependency for scaffolding) |

---

## 7. Pseudocode

### UserRole Enum

```
ENUM UserRole BACKED BY string:
    Admin             = 'admin'
    RestaurantManager = 'restaurant_manager'
    Biker             = 'biker'
```

### User Model

```
CLASS User EXTENDS Authenticatable:
    FILLABLE = [name, email, phone, password, role, restaurant_id, biker_id]
    HIDDEN   = [password, remember_token]

    CASTS:
        email_verified_at  → datetime
        phone_verified_at  → datetime
        password           → hashed
        role               → UserRole enum

    RELATIONSHIP restaurant():
        RETURN belongsTo(Restaurant::class)     // nullable

    RELATIONSHIP biker():
        RETURN belongsTo(Biker::class)          // nullable

    METHOD isAdmin(): boolean
        RETURN this.role === UserRole::Admin

    METHOD isRestaurantManager(): boolean
        RETURN this.role === UserRole::RestaurantManager

    METHOD isBiker(): boolean
        RETURN this.role === UserRole::Biker

    METHOD managedRestaurant(): ?Restaurant
        IF this.role === UserRole::RestaurantManager:
            RETURN this.restaurant
        RETURN NULL

    METHOD bikerProfile(): ?Biker
        IF this.role === UserRole::Biker:
            RETURN this.biker
        RETURN NULL
```

### Magic Link Auth Flow

```
CONTROLLER MagicLinkController:

    METHOD showLoginForm():
        RETURN view('auth.login')

    METHOD sendMagicLink(Request):
        VALIDATE Request: { phone: required|string|max:20 }
        
        user = User::where('phone', Request.phone).first()
        
        IF user IS NULL:
            // Do NOT reveal that phone doesn't exist (security)
            RETURN back()->with('status', 'If this phone is registered, a login link will be sent.')
        
        // Generate signed URL valid for 15 minutes
        signedUrl = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(config('auth.magic_link.expires', 15)),
            ['user' => user.id, 'hash' => sha1(user.phone)]
        )
        
        // Dispatch via WhatsApp service (log fake for dev)
        WhatsappService->sendMagicLink(user.phone, signedUrl)
        
        RETURN back()->with('status', 'If this phone is registered, a login link will be sent.')

    METHOD verifyMagicLink(Request, User, hash):
        IF NOT Request.hasValidSignature():
            ABORT 401, 'Invalid or expired login link'
        
        IF hash !== sha1(User.phone):
            ABORT 401, 'Invalid verification link'
        
        // Mark phone as verified
        User->phone_verified_at = now()
        User->save()
        
        // Authenticate
        Auth::login(User, remember: true)
        
        // Regenerate session to prevent fixation
        Request->session()->regenerate()
        
        RETURN redirect()->intended(route('dashboard'))
```

### WhatsappServiceInterface

```
INTERFACE WhatsappServiceInterface:
    METHOD sendMagicLink(phone: string, url: string): void
    METHOD sendMessage(phone: string, message: string): void
```

### WhatsappLogService (Fake for Development)

```
CLASS WhatsappLogService IMPLEMENTS WhatsappServiceInterface:
    METHOD sendMagicLink(phone, url):
        Log::info("[WhatsApp Fake] Magic link sent to {phone}: {url}")
    
    METHOD sendMessage(phone, message):
        Log::info("[WhatsApp Fake] Message sent to {phone}: {message}")
```

### Role Middleware

```
CLASS EnsureUserRole:
    METHOD handle(Request, Closure next, string ...roles):
        IF NOT Auth::check():
            RETURN redirect()->route('login')
        
        userRole = Auth::user()->role->value
        
        IF userRole NOT IN roles:
            ABORT 403, 'Access denied'
        
        RETURN next(Request)
```

### Gates (Registered in AppServiceProvider::boot)

```
Gate::define('admin', fn(User $user) => $user->isAdmin())
Gate::define('restaurant-manager', fn(User $user) => $user->isRestaurantManager())
Gate::define('biker', fn(User $user) => $user->isBiker())
Gate::define('release-payment', fn(User $user) => $user->isAdmin())          // BR-03
Gate::define('manage-shift-bikers', fn(User $user) => $user->isAdmin())       // BR-05
```

### ShiftPolicy

```
CLASS ShiftPolicy:

    METHOD viewAny(User): bool
        RETURN TRUE   // All authenticated users can list shifts (filtered by role in controller/query)

    METHOD view(User, Shift): bool
        IF User.isAdmin(): RETURN TRUE
        IF User.isRestaurantManager(): RETURN Shift.restaurant_id === User.restaurant_id
        IF User.isBiker(): RETURN TRUE  // Read-only access (shifts they're assigned to)

    METHOD create(User): bool
        RETURN User.isAdmin() OR User.isRestaurantManager()

    METHOD update(User, Shift): bool
        IF User.isAdmin(): RETURN TRUE
        IF User.isRestaurantManager(): RETURN Shift.restaurant_id === User.restaurant_id
        RETURN FALSE

    METHOD delete(User, Shift): bool
        RETURN User.isAdmin()

    METHOD addBiker(User, Shift): bool                   // BR-05
        RETURN User.isAdmin()
```

### State Transitions

```
[Unauthenticated] ──(enter phone)──▶ [Magic Link Requested]
                                          │
                                    (WhatsApp sends link)
                                          │
                                          ▼
[Unauthenticated] ──(click link)──▶ [Verifying Signature]
                                          │
                              ┌───────────┴───────────┐
                              │                       │
                         (valid)                  (invalid/expired)
                              │                       │
                              ▼                       ▼
                    [Authenticated]           [401 Error Page]
                              │
                    (session persists via database driver)
                              │
                              ▼
                    [Dashboard] ──(logout)──▶ [Unauthenticated]
```

### Route Design

| Method | URI | Controller@Method | Auth | Middleware |
|--------|-----|-------------------|------|------------|
| GET | `/login` | `MagicLinkController@showLoginForm` | Guest | `guest` |
| POST | `/login` | `MagicLinkController@sendMagicLink` | Guest | `guest` |
| GET | `/auth/magic-link/verify/{user}/{hash}` | `MagicLinkController@verifyMagicLink` | Guest | `signed` |
| GET | `/dashboard` | Closure or `DashboardController@index` | Any | `auth` |
| POST | `/logout` | Closure ( `Auth::logout()` + redirect ) | Any | `auth` |

---

## 8. Edge Cases

1. **Phone number not registered** — `sendMagicLink` must NOT reveal whether a phone exists. Always return the same success message to prevent user enumeration.

2. **Expired magic link** — User clicks link after 15 minutes. `hasValidSignature()` returns false → 401. User must request a new link.

3. **Reused magic link** — After first click, the signed URL is consumed. Laravel's `hasValidSignature()` doesn't inherently prevent replay. Consider using a `password_reset_tokens`-style table or cache key to track used tokens. **Recommendation:** For MVP, accept that links are single-use via timestamp; stricter replay prevention deferred.

4. **User with no role** — Default role is `admin` in migration, so this should never happen. But if it does, `isAdmin()`, `isRestaurantManager()`, `isBiker()` all return false → no access.

5. **Restaurant Manager with null restaurant_id** — The relationship `restaurant()` returns null. Policy checks `Shift.restaurant_id === User.restaurant_id` → comparison fails → access denied. Safe fallback.

6. **Biker with null biker_id** — Same safe fallback. Policy denies access to entities that require a linked biker profile.

7. **Existing tests with User factory** — The `DatabaseSeeder` creates a test user. The factory must continue to work with default state that satisfies existing tests. Add new fields as optional states, not as required defaults that break the seeder.

8. **Email uniqueness constraint** — Making `email` nullable means multiple users could have `NULL` email. MySQL treats NULL as unique in UNIQUE indexes (multiple NULLs allowed). Safe.

9. **Phone number formatting** — Phone numbers may include country codes, spaces, dashes. The migration stores VARCHAR(20). The application should normalize phone numbers to E.164 format before lookup. **Recommendation:** Add a mutator on the User model to strip non-digits and validate format.

10. **Concurrent magic link requests** — User requests multiple magic links. The most recent link is valid; older links still have valid signatures but may confuse the user. **Recommendation:** Acceptable for MVP; rate-limit magic link requests (throttle middleware on POST /login).

11. **Session fixation** — After magic link verification, `session()->regenerate()` prevents session fixation attacks.

12. **Existing shift.created_by values** — The `shifts.created_by` column is currently nullable with no FK. Existing rows have NULL. Adding the FK constraint must not break existing data (ON DELETE SET NULL, nullable is fine).

13. **Breeze default routes** — After installing Breeze, it registers many routes (register, login, password reset, etc.). We must remove/override the default login route to replace with magic link. Other Breeze routes (password confirmation, etc.) can remain but won't be actively used.

---

## 9. Acceptance Criteria

### User Model & Schema

- [ ] AC-01: Migration adds `phone` column (VARCHAR(20), nullable, unique) to `users` table.
- [ ] AC-02: Migration adds `phone_verified_at` column (timestamp, nullable) to `users` table.
- [ ] AC-03: Migration adds `role` column (VARCHAR(30), NOT NULL, default 'admin') to `users` table.
- [ ] AC-04: Migration adds `restaurant_id` column (BIGINT UNSIGNED, nullable, FK → restaurants) to `users` table.
- [ ] AC-05: Migration adds `biker_id` column (BIGINT UNSIGNED, nullable, FK → bikers) to `users` table.
- [ ] AC-06: Migration makes `email` and `password` columns nullable on `users` table.
- [ ] AC-07: Migration adds FK constraint `shifts.created_by` → `users.id` (ON DELETE SET NULL).
- [ ] AC-08: Both new migrations have reversible `down()` methods that drop added columns/keys.

### UserRole Enum

- [ ] AC-09: `UserRole` is a backed string enum with cases: `Admin='admin'`, `RestaurantManager='restaurant_manager'`, `Biker='biker'`.
- [ ] AC-10: `UserRole` has a `labels()` method returning human-readable labels for each case.

### User Model

- [ ] AC-11: `User` model casts `role` to `UserRole` enum.
- [ ] AC-12: `User` model has `belongsTo` relationship `restaurant()` (nullable).
- [ ] AC-13: `User` model has `belongsTo` relationship `biker()` (nullable).
- [ ] AC-14: `User::isAdmin()` returns `true` only when `role === UserRole::Admin`.
- [ ] AC-15: `User::isRestaurantManager()` returns `true` only when `role === UserRole::RestaurantManager`.
- [ ] AC-16: `User::isBiker()` returns `true` only when `role === UserRole::Biker`.

### Auth — Magic Link

- [ ] AC-17: `GET /login` shows a form with a phone number input field.
- [ ] AC-18: `POST /login` with a registered phone number creates a signed URL with 15-minute expiry.
- [ ] AC-19: `POST /login` with an unregistered phone number does NOT reveal the phone doesn't exist (same success message).
- [ ] AC-20: Signed URL is dispatched via `WhatsappServiceInterface` (log fake in dev).
- [ ] AC-21: `GET /auth/magic-link/verify/{user}/{hash}` with valid signature authenticates the user and sets `phone_verified_at`.
- [ ] AC-22: `GET /auth/magic-link/verify/{user}/{hash}` with expired/invalid signature returns 401.
- [ ] AC-23: `GET /auth/magic-link/verify/{user}/{hash}` with wrong hash returns 401.
- [ ] AC-24: Session is regenerated after successful authentication (session fixation prevention).
- [ ] AC-25: `POST /logout` logs out the user and redirects to `/login`.

### Authorization — Gates

- [ ] AC-26: `Gate::allows('admin')` returns `true` only for Admin users.
- [ ] AC-27: `Gate::allows('restaurant-manager')` returns `true` only for RestaurantManager users.
- [ ] AC-28: `Gate::allows('biker')` returns `true` only for Biker users.
- [ ] AC-29: `Gate::allows('release-payment')` returns `true` only for Admin users (BR-03).
- [ ] AC-30: `Gate::allows('manage-shift-bikers')` returns `true` only for Admin users (BR-05).

### Authorization — Policies

- [ ] AC-31: `ShiftPolicy@create` allows Admin and RestaurantManager, denies Biker.
- [ ] AC-32: `ShiftPolicy@update` allows Admin always; allows RestaurantManager only for their own restaurant's shifts; denies Biker.
- [ ] AC-33: `ShiftPolicy@delete` allows Admin only.
- [ ] AC-34: `ShiftPolicy@addBiker` allows Admin only (BR-05 enforcement).
- [ ] AC-35: `ShiftPolicy@view` allows Admin always; RestaurantManager for own restaurant; Biker read-only.

### Middleware

- [ ] AC-36: `role:admin` middleware grants access to Admin only, returns 403 for others.
- [ ] AC-37: `role:restaurant_manager` middleware grants access to RestaurantManager only.
- [ ] AC-38: `role:biker` middleware grants access to Biker only.
- [ ] AC-39: Unauthenticated user hitting `role` middleware is redirected to `/login`.

### Session Configuration

- [ ] AC-40: Session driver is confirmed as `database` (already configured, verify in test).
- [ ] AC-41: Authenticated session persists across requests using the `sessions` table.

### Regression

- [ ] AC-42: All 205 existing tests pass with zero failures after all changes.
- [ ] AC-43: Existing model factories (Restaurant, Biker, Shift, ShiftBiker, PixKey, Payment, PaymentAuditLog) continue to work unchanged.

### Service Interface

- [ ] AC-44: `WhatsappServiceInterface` defines `sendMagicLink(string $phone, string $url): void`.
- [ ] AC-45: `WhatsappLogService` implements the interface and writes to Laravel log.
- [ ] AC-46: `WhatsappServiceInterface` is bound to `WhatsappLogService` in the service container.

---

## 10. Security Considerations

- **Authorization:** Three-tier role system enforced at gate, policy, and middleware levels. No user can escalate privileges. Default role is `admin` for safety during migration (no locked-out users).
- **Input Validation:** Phone numbers validated (required, string, max 20 chars). Server-side normalization to digits-only before lookup. Signed URL hash includes `sha1(user.phone)` to prevent tampering.
- **Container Compliance:** All operations within `/workspaces/bikerflow`. `composer require laravel/breeze` runs inside the Docker container. No external access needed.
- **Session Security:** Database driver already configured. Session regeneration on login prevents fixation. `http_only` cookies enabled (default).
- **User Enumeration Prevention:** Magic link request always returns the same message regardless of whether the phone is registered.
- **Signed URL Integrity:** Laravel's `URL::temporarySignedRoute` provides HMAC-based tamper protection with expiration.
- **Rate Limiting:** `POST /login` should be throttled (e.g., 5 requests per minute per IP) to prevent abuse. Use Laravel's `throttle` middleware.
- **Phone Verification:** `phone_verified_at` is set on first successful magic link click, providing identity confirmation.
- **No Password Storage for Phone Users:** Password column is nullable; magic link users don't need passwords. Existing password-based Breeze scaffolding routes should be disabled/removed.

---

## 11. Implementation Sequence

The Developer should implement in this order to maintain testability:

| Step | Component | Dependency |
|------|-----------|------------|
| 1 | Snapshot via `./bin/agent-jail/snapshot.sh` | None |
| 2 | `composer require laravel/breeze` (Blade stack) inside container | Step 1 |
| 3 | `php artisan breeze:install blade` — accept defaults | Step 2 |
| 4 | `UserRole` enum | Step 3 |
| 5 | Migration: add columns to users + FK on shifts | Step 4 |
| 6 | Run migration, verify 205 tests still pass | Step 5 |
| 7 | Update `User` model (casts, relationships, helpers) | Step 5 |
| 8 | Update `UserFactory` (phone, role states) | Step 7 |
| 9 | `WhatsappServiceInterface` + `WhatsappLogService` | None |
| 10 | `MagicLinkController` + views (login, sent, verify) | Steps 7, 9 |
| 11 | `EnsureUserRole` middleware | Step 7 |
| 12 | Register middleware + auth routes in `bootstrap/app.php` and `routes/web.php` | Steps 10, 11 |
| 13 | Gates in `AppServiceProvider` | Step 7 |
| 14 | Policies (Shift, Restaurant, Biker) | Steps 7, 13 |
| 15 | Remove/disable unused Breeze default routes (password reset, registration, email verification) | Step 10 |
| 16 | Update `DatabaseSeeder` with admin user + phone | Step 8 |
| 17 | Run all 205 tests + new auth tests | All steps |
| 18 | `npm run build` to compile Breeze assets | Step 3 |

---

## 12. File Dependency Map

```
UserRole enum
    └── User model (casts)
        ├── UserFactory (states)
        ├── EnsureUserRole middleware
        ├── Gates (AppServiceProvider)
        ├── ShiftPolicy
        ├── RestaurantPolicy
        ├── BikerPolicy
        ├── MagicLinkController
        └── DatabaseSeeder

WhatsappServiceInterface
    └── WhatsappLogService
        └── MagicLinkController

Migration (users columns)
    └── User model
        └── (everything above)

Migration (shifts FK)
    └── Shift model (no code change needed, FK is DB-level)

Breeze Install
    ├── views/auth/* (replaced by our custom views)
    ├── routes/auth.php (modified to use MagicLinkController)
    └── assets (Tailwind, app.css, app.js)
```

---

## 13. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Breeze install breaks existing tests | Medium | High | Snapshot before install; run tests immediately after Breeze scaffolding before any customization |
| Breeze overwrites `User` model or `UserFactory` | High | Medium | Snapshot before install; merge Breeze changes with our customizations manually |
| Making `email` nullable breaks Breeze's default validation | High | Low | Override Breeze's request classes or disable unused Breeze routes |
| Phone number format inconsistency causes auth failures | Medium | Medium | Normalize phone in model mutator and in controller before lookup |
| Adding FK to `shifts.created_by` fails if orphaned data exists | Low | High | Check for non-null `created_by` values before running migration; SET NULL on delete handles gracefully |
| Breeze publishes too many unused routes/views | Medium | Low | Prune unused routes after install; document which Breeze features are disabled |
