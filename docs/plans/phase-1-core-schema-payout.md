# Plan: Phase 1 — Core Schema, Entities & Payout Formula

> **ADR-001** records the architectural decisions behind this plan. See `docs/adr/001-core-payout-schema.md`.

**Task ID:** phase-1
**Date:** 2026-05-14
**Planner Version:** 2.0 (implementation-grade delta plan)
**Based On:** v1.1 plan (all 3 open questions resolved, Option C adopted)
**Complexity:** Complex

---

## 1. Objective

Complete the foundational data layer of BikerFlow: all 7 database migrations, 7 Eloquent models with full relationships, 5 PHP backed enums, 2 financial services (PayoutService already validated, RevenueService already implemented), 1 custom exception, 7 model factories, and comprehensive test coverage satisfying AC-01 through AC-41 (including AC-36a through AC-38a for Option C draft state).

This plan is a **delta plan** — it identifies what already exists, what needs modification, and what must be created. It is structured for direct Developer execution.

---

## 2. Source References

### User Stories
- None directly. This is foundational infrastructure that US-01 through US-04 depend on.

### Business Rules
- **BR-01** Workflow Locking — `workflow_type` on `shifts` locked when status ≠ `draft`. Enforced at model level via `WorkflowLockedException`. While in `draft`, `workflow_type` is editable.
- **BR-03** Manual Release / Payout Formula — Core financial calculation logic. PayoutService and RevenueService implement this verbatim.
- **BR-04** Granular Failure — Each `payment` row is per-biker-per-shift, independent status.
- **BR-06** Payment Retries — `payment_audit_logs` table with unique `transaction_ref` per attempt.

### PRD Sections
- Section 2: User Personas & Main Flows
- Section 3: Rate & Revenue Management (both formulas)
- Section 4: Business Rules (BR-01 through BR-06)
- Section 5: Functional Requirements (US-01 through US-04)

### Tech Doc Sections
- Section 1: Primary Tech Stack (Laravel 13, MySQL 8.4)
- Section 3: Business Logic & Formulas
- Section 5: Security & Guardrails

---

## 3. Scope

### In Scope

1. **3 new database migrations**: pix_keys, payments, payment_audit_logs
2. **1 migration modification**: shifts table (status default, started_at nullable, created_by column, indexes)
3. **3 new Eloquent models**: PixKey, Payment, PaymentAuditLog (with full relationships)
4. **4 model modifications**: Restaurant, Biker, Shift, ShiftBiker (add missing relationships)
5. **5 PHP backed enums**: ShiftStatus, WorkflowType, PaymentStatus, PixKeyType, PaymentAuditAction
6. **1 custom exception**: WorkflowLockedException
7. **RevenueService**: already implemented — verify alignment only, do NOT overwrite
8. **PayoutService**: already implemented and validated — do NOT touch
9. **7 model factories**: Restaurant, Biker, PixKey, Shift, ShiftBiker, Payment, PaymentAuditLog
10. **2 test bug fixes**: correct expected values in PayoutServiceTest
11. **New tests**: Model relationships, enum coverage, BR-01 enforcement, factory validation

### Out of Scope

1. Auth / Users table changes / WhatsApp Magic Link
2. Controllers, Routes, Middleware, Views, React components
3. HTTP endpoints for shift creation, closing, trip incrementing
4. PIX bank API integration (FitBank, Stark Bank)
5. Live Tick increment API, Manual Entry API
6. Payment processing / PIX payment execution
7. Admin approval/release flow (BR-03 controller-level)
8. Biker self-registration
9. US-01 through US-04 implementations
10. Notification system

### Resolved Questions

1. **User/Auth references:** ✅ Include nullable `created_by` / `released_by` columns now. FKs wired when auth implemented.
2. **Trip-level granularity:** ✅ Aggregate `trips_count` sufficient for Phase 1.
3. **Shift draft state:** ✅ Option C — Draft with pre-selected workflow. `workflow_type` editable in `draft`, locks on `draft` → `open`.

---

## 4. Business Rules Matrix

| Rule | Applies? | Implementation Constraint |
|------|----------|--------------------------|
| BR-01 Workflow Locking | Yes | `Shift` model throws `WorkflowLockedException` if `workflow_type` modified when `status != 'draft'`. State guard prevents skipping from `draft` to non-`open` statuses. |
| BR-02 PIX Verification | Partial | `pix_keys` table includes `is_verified` boolean and `verified_at` timestamp. Bank API verification deferred. |
| BR-03 Manual Release | Partial | `payments.status` requires explicit transition to `processing`. `released_at` records approval. Controller enforcement deferred. |
| BR-04 Granular Failure | Yes | Each `payment` row is per-`shift_biker`. Independent status. `payment_audit_logs` per-payment. |
| BR-05 Last Minute Biker | Partial | `shift_biker` pivot allows adding bikers. Admin-only auth deferred. |
| BR-06 Payment Retries | Yes | `payment_audit_logs.transaction_ref` UNIQUE prevents double-billing. Every retry is a new row. |

---

## 5. Current State Analysis (Delta Inventory)

### ✅ Already Exists and Matches Plan — DO NOT TOUCH

| Component | File | Status |
|-----------|------|--------|
| PayoutService | `app/Services/PayoutService.php` | 🟢 Validated, matches BR-03 exactly |
| RevenueService | `app/Services/RevenueService.php` | 🟢 Implemented, matches formula |
| PayoutServiceTest | `tests/Unit/PayoutServiceTest.php` | 🟡 2 failures — test bugs, not service bugs |
| RevenueServiceTest | `tests/Unit/RevenueServiceTest.php` | 🟢 All pass |
| PayoutIntegrationTest | `tests/Feature/Payout/PayoutIntegrationTest.php` | 🟢 All pass |
| restaurants migration | `database/migrations/2026_05_14_000001_create_restaurants_table.php` | 🟢 Matches plan |
| bikers migration | `database/migrations/2026_05_14_000002_create_bikers_table.php` | 🟢 Matches plan |
| shift_bikers migration | `database/migrations/2026_05_14_000004_create_shift_bikers_table.php` | 🟢 Matches plan |
| Restaurant model (fillable + casts) | `app/Models/Restaurant.php` | 🟡 Missing relationships |
| Biker model (fillable + casts) | `app/Models/Biker.php` | 🟡 Missing relationships |
| ShiftBiker model (fillable + casts) | `app/Models/ShiftBiker.php` | 🟡 Missing relationships |

### 🟡 Exists But Needs Modification

| Component | File | What Needs Changing |
|-----------|------|-------------------|
| Shifts migration | `database/migrations/2026_05_14_000003_create_shifts_table.php` | See §6.1 below — 4 changes required |
| Restaurant model | `app/Models/Restaurant.php` | Add `hasMany(Shift::class)` relationship |
| Biker model | `app/Models/Biker.php` | Add `hasMany(PixKey::class)`, `hasMany(ShiftBiker::class)` |
| Shift model | `app/Models/Shift.php` | Add `hasMany(ShiftBiker::class)`, enum casts, BR-01 model event |
| ShiftBiker model | `app/Models/ShiftBiker.php` | Add `hasOne(Payment::class)` relationship |
| PayoutServiceTest | `tests/Unit/PayoutServiceTest.php` | Fix 2 incorrect expected values (see §6.3) |
| PayoutIntegrationTest | `tests/Feature/Payout/PayoutIntegrationTest.php` | Update to use `draft` default status and nullable `started_at` |

### ❌ Does Not Exist — Must Create

| Component | Count | Files |
|-----------|-------|-------|
| Enums | 5 | `app/Enums/ShiftStatus.php`, `WorkflowType.php`, `PaymentStatus.php`, `PixKeyType.php`, `PaymentAuditAction.php` |
| Exception | 1 | `app/Exceptions/WorkflowLockedException.php` |
| Migrations | 3 | `create_pix_keys_table`, `create_payments_table`, `create_payment_audit_logs_table` |
| Models | 3 | `PixKey.php`, `Payment.php`, `PaymentAuditLog.php` |
| Factories | 7 | All 7 entity factories |
| Tests | ~6 files | Model tests, BR-01 tests, enum tests, factory tests |

---

## 6. Implementation Instructions

### 6.1 Fix Existing: Shifts Migration

**File:** `database/migrations/2026_05_14_000003_create_shifts_table.php`

**Current state (WRONG for Option C):**
```
$table->string('workflow_type', 20)->default('live_tick');
$table->string('status', 20)->default('open');            // WRONG: should be 'draft'
$table->decimal('restaurant_rate', 12, 2)->default('0.00');
$table->timestamp('started_at');                           // WRONG: not nullable
// MISSING: created_by column
// MISSING: indexes
```

**Required changes:**
1. `status` default: `'open'` → `'draft'` (Option C)
2. `started_at`: make nullable (`->nullable()`) — set on `draft` → `open` transition, NULL at creation
3. Add `created_by`: `$table->unsignedBigInteger('created_by')->nullable()`
4. Add composite index: `$table->index(['restaurant_id', 'status'])`
5. Add status index: `$table->index('status')`

**Approach:** Since this is dev-only (no production data), modify the existing migration file directly. Run `php artisan migrate:fresh` after.

### 6.2 Fix Existing: Test Bugs

**File:** `tests/Unit/PayoutServiceTest.php`

**Bug:** The "large numbers" expected value is incorrect.

- **Line ~312 (data provider):** `'large numbers' => ['999999.99', '99999.99', 999, '100089990.00']`
  - Change expected to `'100899990.00'` (verified: 999999.99 + 99999.99×999 = 999999.99 + 99899990.01 = **100,899,990.00**)
- **Line ~360 (explicit test):** `$this->assertEquals('100089990.00', ...)`
  - Change expected to `'100899990.00'`

**Verification:** `python3 -c "print(999999.99 + 99999.99 * 999)"` → `100899990.0` ✅

### 6.3 Fix Existing: PayoutIntegrationTest

**File:** `tests/Feature/Payout/PayoutIntegrationTest.php`

After the shifts migration is fixed (default status `draft`, nullable `started_at`), update all test cases that create shifts:

1. Every `Shift::create([...])` call with `'status' => 'open'` and `'started_at' => now()` remains valid (explicitly sets status to open)
2. Verify all tests still pass after migration change — the explicit `'status' => 'open'` in tests overrides the new default

**Action:** Run the integration tests after migration fix. If they pass, no changes needed. If they fail, add explicit `'status' => 'open'` and `'started_at' => now()` where missing.

### 6.4 Create: 5 PHP Backed Enums

All enums use `string` backing type and live in `app/Enums/`.

#### ShiftStatus
```
enum ShiftStatus: string
    case Draft = 'draft'
    case Open = 'open'
    case Closed = 'closed'
    case Approved = 'approved'
    case Paid = 'paid'
```

#### WorkflowType
```
enum WorkflowType: string
    case LiveTick = 'live_tick'
    case ManualEntry = 'manual_entry'
```

#### PaymentStatus
```
enum PaymentStatus: string
    case Pending = 'pending'
    case Processing = 'processing'
    case Paid = 'paid'
    case Failed = 'failed'
```

#### PixKeyType
```
enum PixKeyType: string
    case Cpf = 'cpf'
    case Phone = 'phone'
    case Email = 'email'
    case Random = 'random'
```

#### PaymentAuditAction
```
enum PaymentAuditAction: string
    case Create = 'create'
    case Release = 'release'
    case Attempt = 'attempt'
    case Retry = 'retry'
    case Fail = 'fail'
    case Succeed = 'succeed'
```

**Note on plan discrepancy:** The v1.1 plan scope mentions "6 enums" but only lists 5 in the Create table (no `ShiftBikerStatus`). The `shift_bikers` table has no `status` column, so no 6th enum is needed. This is a plan count error, not a missing feature.

### 6.5 Create: WorkflowLockedException

**File:** `app/Exceptions/WorkflowLockedException.php`

```
CLASS WorkflowLockedException EXTENDS \RuntimeException

    PROPERTIES:
        private readonly Shift $shift
        private readonly string $attemptedValue

    CONSTRUCTOR(shift: Shift, attemptedValue: string):
        parent::__construct("Cannot change workflow_type after shift has started")
        this.shift = shift
        this.attemptedValue = attemptedValue

    METHOD getShift(): Shift
        RETURN this.shift

    METHOD getAttemptedValue(): string
        RETURN this.attemptedValue
```

### 6.6 Create: 3 New Migrations

Use timestamps `2026_05_14_000005`, `2026_05_14_000006`, `2026_05_14_000007`.

#### pix_keys table
```
Schema::create('pix_keys', function (Blueprint $table) {
    $table->id();
    $table->foreignId('biker_id')->constrained('bikers')->cascadeOnDelete();
    $table->string('key_type', 20);                          // cpf, phone, email, random
    $table->string('key_value');
    $table->string('account_holder_name')->nullable();       // populated after bank API verification
    $table->boolean('is_verified')->default(false);
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();

    $table->unique(['biker_id', 'key_type', 'key_value']);   // prevent duplicate PIX keys per biker
    $table->index('biker_id');                                // lookup keys by biker
});
```

#### payments table
```
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shift_biker_id')->constrained('shift_bikers')->cascadeOnDelete();
    $table->decimal('amount', 12, 2)->default('0.00');
    $table->string('status', 20)->default('pending');        // pending, processing, paid, failed
    $table->unsignedBigInteger('released_by')->nullable();    // FK to users — added when auth implemented
    $table->timestamp('released_at')->nullable();             // when admin approved (BR-03)
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();

    $table->index('shift_biker_id');                          // lookup payment by assignment
    $table->index('status');                                  // query payments by status
});
```

#### payment_audit_logs table
```
Schema::create('payment_audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
    $table->string('action', 20);                             // create, release, attempt, retry, fail, succeed
    $table->string('transaction_ref')->unique();              // BR-06: unique per attempt
    $table->json('payload')->nullable();                      // request/response data from bank API
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index('payment_id');                              // lookup audit trail for a payment
});
```

### 6.7 Modify: 4 Existing Models (Add Relationships)

#### Restaurant model — add:
```
use Illuminate\Database\Eloquent\Relations\HasMany;

public function shifts(): HasMany
{
    return $this->hasMany(Shift::class);
}
```

#### Biker model — add:
```
use Illuminate\Database\Eloquent\Relations\HasMany;

public function pixKeys(): HasMany
{
    return $this->hasMany(PixKey::class);
}

public function shiftBikers(): HasMany
{
    return $this->hasMany(ShiftBiker::class);
}
```

#### ShiftBiker model — add:
```
use Illuminate\Database\Eloquent\Relations\HasOne;

public function payment(): HasOne
{
    return $this->hasOne(Payment::class);
}
```

#### Shift model — add relationship + enum casts + BR-01 enforcement:
```
use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use App\Exceptions\WorkflowLockedException;
use Illuminate\Database\Eloquent\Relations\HasMany;

// In casts():
'workflow_type' => WorkflowType::class,
'status' => ShiftStatus::class,

// Add relationship:
public function shiftBikers(): HasMany
{
    return $this->hasMany(ShiftBiker::class);
}

// Add BR-01 model event in boot() or via `saved` event:
// ON Shift.saving:
//   IF this.status !== ShiftStatus::Draft AND this.isDirty('workflow_type'):
//     THROW WorkflowLockedException(shift: this, attemptedValue: this.workflow_type)
//
// Add state transition guard:
//   IF this.isDirty('status'):
//     $new = this.status
//     IF $new is Closed, Approved, or Paid AND original was Draft:
//       THROW exception — must go through Open first (AC-38a)
```

### 6.8 Create: 3 New Models

#### PixKey model
```
File: app/Models/PixKey.php

$fillable = ['biker_id', 'key_type', 'key_value', 'account_holder_name', 'is_verified', 'verified_at']

casts():
    'is_verified' => 'boolean'
    'verified_at' => 'datetime'

Relationships:
    belongsTo Biker::class
```

#### Payment model
```
File: app/Models/Payment.php

$fillable = ['shift_biker_id', 'amount', 'status', 'released_by', 'released_at', 'paid_at']

casts():
    'amount' => 'decimal:2'
    'status' => PaymentStatus::class
    'released_at' => 'datetime'
    'paid_at' => 'datetime'

Relationships:
    belongsTo ShiftBiker::class
    hasMany PaymentAuditLog::class
```

#### PaymentAuditLog model
```
File: app/Models/PaymentAuditLog.php

$fillable = ['payment_id', 'action', 'transaction_ref', 'payload', 'error_message']

casts():
    'action' => PaymentAuditAction::class
    'payload' => 'array'

Relationships:
    belongsTo Payment::class
```

### 6.9 Create: 7 Model Factories

All factories use explicit financial defaults — NO random monetary values. Financial fields always return valid 2-decimal-place strings.

#### RestaurantFactory
```
'name' => fake()->company()
'rate_per_trip' => '15.00'        // fixed default, not random
'active' => true
```

#### BikerFactory
```
'name' => fake()->name()
'phone' => fake()->unique()->numerify('11#########')    // 11 digits
'rate_per_trip' => '10.00'        // fixed default
'base_fee' => '25.00'             // fixed default
'active' => true
```

#### PixKeyFactory
```
'biker_id' => Biker::factory()
'key_type' => 'cpf'
'key_value' => fake()->unique()->numerify('###########')
'is_verified' => false
// account_holder_name and verified_at left null by default
```

#### ShiftFactory
```
'restaurant_id' => Restaurant::factory()
'workflow_type' => 'live_tick'
'status' => 'draft'               // Option C default
'restaurant_rate' => '15.00'      // fixed default
// started_at null (draft state)
// closed_at null

// Named states:
// ->started(): sets status to 'open', started_at to now()
// ->closed(): sets status to 'closed', closed_at to now(), requires started first
```

#### ShiftBikerFactory
```
'shift_id' => Shift::factory()
'biker_id' => Biker::factory()
'trips_count' => 0
'biker_rate' => '10.00'           // fixed default
'base_fee' => '25.00'             // fixed default
```

#### PaymentFactory
```
'shift_biker_id' => ShiftBiker::factory()
'amount' => '0.00'
'status' => 'pending'
// released_by null, released_at null, paid_at null
```

#### PaymentAuditLogFactory
```
'payment_id' => Payment::factory()
'action' => 'create'
'transaction_ref' => fake()->unique()->uuid()
// payload null, error_message null
```

### 6.10 Pseudocode: Shift Model BR-01 Enforcement

```
CLASS Shift EXTENDS Model

    // In the saved/saving boot event:

    STATIC METHOD boot():
        static.saving(function (Shift $shift):
            // BR-01: Workflow type locking
            IF $shift.status !== ShiftStatus::Draft AND $shift.isDirty('workflow_type'):
                THROW WorkflowLockedException(
                    shift: $shift,
                    attemptedValue: $shift.workflow_type
                )

            // AC-38a: State transition guard
            IF $shift.isDirty('status'):
                $originalStatus = $shift.getOriginal('status')
                $newStatus = $shift.status

                // Cannot skip from draft to anything except open
                IF $originalStatus === ShiftStatus::Draft
                   AND $newStatus !== ShiftStatus::Open
                   AND $newStatus !== ShiftStatus::Draft:
                    THROW new InvalidOperationException(
                        "Shift must transition to 'open' before '{$newStatus->value}'"
                    )

                // When transitioning draft → open, set started_at
                IF $newStatus === ShiftStatus::Open
                   AND $originalStatus === ShiftStatus::Draft:
                    $shift.started_at = now()
        )
```

### 6.11 Pseudocode: Shift State Transitions (Option C)

```
[Create with workflow_type]
       │
       ▼
    draft ────────(editable)──────────┐   workflow_type is EDITABLE
       │                               │   bikers can be added/removed
       │ (start)                       │   started_at is NULL
       ▼                               │
      open ──(close)──▶ closed ──(approve)──▶ approved ──(release payment)──▶ paid
       │                                                                 │
       │  BR-01: workflow_type is LOCKED from this point                  │
       │  started_at is set                                               │
       │                                                                  │
                                    payment failure ────────────────┤
                                                                            │
                                                                    payment stays at
                                                                    'failed' (retry possible)
```

### 6.12 Pseudocode: Payment Status Transitions

```
[Created] ──▶ pending ──(admin approves)──▶ processing ──(bank confirms)──▶ paid
                  │                              │
                  │                              └──(bank rejects)──▶ failed ──(retry)──▶ pending
                  │                                                                 │
                  └── Every transition creates a PaymentAuditLog entry              │
                      with unique transaction_ref (BR-06)                           │
```

---

## 7. Schema Changes — Complete Reference

### New Tables

```
pix_keys
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── biker_id              BIGINT UNSIGNED FK(bikers.id) CASCADE DELETE
├── key_type              VARCHAR(20) NOT NULL       — cpf, phone, email, random
├── key_value             VARCHAR(255) NOT NULL
├── account_holder_name   VARCHAR(255) NULL          — populated after bank API verification (BR-02)
├── is_verified           TINYINT(1) NOT NULL DEFAULT 0
├── verified_at           TIMESTAMP NULL
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL
    UNIQUE INDEX: (biker_id, key_type, key_value)
    INDEX: (biker_id)

payments
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── shift_biker_id        BIGINT UNSIGNED FK(shift_bikers.id) CASCADE DELETE
├── amount                DECIMAL(12,2) NOT NULL DEFAULT '0.00'
├── status                VARCHAR(20) NOT NULL DEFAULT 'pending'
├── released_by           BIGINT UNSIGNED NULL
├── released_at           TIMESTAMP NULL
├── paid_at               TIMESTAMP NULL
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL
    INDEX: (shift_biker_id)
    INDEX: (status)

payment_audit_logs
├── id                    BIGINT UNSIGNED PK AUTO_INCREMENT
├── payment_id            BIGINT UNSIGNED FK(payments.id) CASCADE DELETE
├── action                VARCHAR(20) NOT NULL
├── transaction_ref       VARCHAR(255) NOT NULL UNIQUE
├── payload               JSON NULL
├── error_message         TEXT NULL
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL
    UNIQUE INDEX: (transaction_ref)
    INDEX: (payment_id)
```

### Modified Table: shifts

```
shifts (CHANGES)
├── workflow_type         VARCHAR(20) NOT NULL DEFAULT 'live_tick'    ← unchanged
├── status                VARCHAR(20) NOT NULL DEFAULT 'draft'        ← CHANGED from 'open'
├── started_at            TIMESTAMP NULL                              ← CHANGED to nullable
├── + created_by          BIGINT UNSIGNED NULL                        ← NEW column
├── created_at            TIMESTAMP NULL
└── updated_at            TIMESTAMP NULL
    + INDEX: (restaurant_id, status)                                  ← NEW
    + INDEX: (status)                                                 ← NEW
```

### Unchanged Tables

| Table | Status |
|-------|--------|
| `restaurants` | ✅ Matches plan |
| `bikers` | ✅ Matches plan |
| `shift_bikers` | ✅ Matches plan |

### Financial Column Checklist

| Column | Table | Type | BCMath in Code? |
|--------|-------|------|-----------------|
| rate_per_trip | restaurants | DECIMAL(12,2) | Yes |
| rate_per_trip | bikers | DECIMAL(12,2) | Yes |
| base_fee | bikers | DECIMAL(12,2) | Yes |
| restaurant_rate | shifts | DECIMAL(12,2) | Yes |
| biker_rate | shift_bikers | DECIMAL(12,2) | Yes |
| base_fee | shift_bikers | DECIMAL(12,2) | Yes |
| amount | payments | DECIMAL(12,2) | Yes |

---

## 8. Affected Files — Complete Inventory

### Create (New Files)

| Layer | File Path | Purpose |
|-------|-----------|---------|
| **Enum** | `app/Enums/ShiftStatus.php` | draft, open, closed, approved, paid |
| **Enum** | `app/Enums/WorkflowType.php` | live_tick, manual_entry |
| **Enum** | `app/Enums/PaymentStatus.php` | pending, processing, paid, failed |
| **Enum** | `app/Enums/PixKeyType.php` | cpf, phone, email, random |
| **Enum** | `app/Enums/PaymentAuditAction.php` | create, release, attempt, retry, fail, succeed |
| **Exception** | `app/Exceptions/WorkflowLockedException.php` | Thrown on BR-01 violation |
| **Migration** | `database/migrations/2026_05_14_000005_create_pix_keys_table.php` | PIX keys table |
| **Migration** | `database/migrations/2026_05_14_000006_create_payments_table.php` | Payments table |
| **Migration** | `database/migrations/2026_05_14_000007_create_payment_audit_logs_table.php` | Audit log table |
| **Model** | `app/Models/PixKey.php` | PIX key entity with verification tracking |
| **Model** | `app/Models/Payment.php` | Payment entity with status tracking |
| **Model** | `app/Models/PaymentAuditLog.php` | Audit trail with unique transaction_ref |
| **Factory** | `database/factories/RestaurantFactory.php` | Explicit financial defaults |
| **Factory** | `database/factories/BikerFactory.php` | Explicit financial defaults |
| **Factory** | `database/factories/PixKeyFactory.php` | Test factory |
| **Factory** | `database/factories/ShiftFactory.php` | Named states: draft (default), started |
| **Factory** | `database/factories/ShiftBikerFactory.php` | Test factory |
| **Factory** | `database/factories/PaymentFactory.php` | Test factory |
| **Factory** | `database/factories/PaymentAuditLogFactory.php` | Test factory |
| **Test** | `tests/Feature/Models/RestaurantModelTest.php` | AC-09: hasMany shifts |
| **Test** | `tests/Feature/Models/BikerModelTest.php` | AC-10: hasMany pixKeys, shiftBikers |
| **Test** | `tests/Feature/Models/ShiftModelTest.php` | AC-12, AC-36→AC-38a: relationships + BR-01 |
| **Test** | `tests/Feature/Models/ShiftBikerModelTest.php` | AC-13: relationships + hasOne payment |
| **Test** | `tests/Feature/Models/PaymentModelTest.php` | AC-14, AC-15: relationships + audit logs |
| **Test** | `tests/Feature/Models/PaymentAuditLogModelTest.php` | AC-15: belongsTo + BR-06 uniqueness |
| **Test** | `tests/Feature/Models/PixKeyModelTest.php` | AC-11: belongsTo biker |
| **Test** | `tests/Feature/Factories/FactoryTest.php` | AC-39, AC-40, AC-41: factory validation |

### Modify (Existing Files)

| Layer | File Path | Change Description |
|-------|-----------|-------------------|
| Migration | `database/migrations/2026_05_14_000003_create_shifts_table.php` | Fix status default to 'draft', make started_at nullable, add created_by, add indexes |
| Model | `app/Models/Restaurant.php` | Add `shifts()` hasMany relationship |
| Model | `app/Models/Biker.php` | Add `pixKeys()` and `shiftBikers()` hasMany relationships |
| Model | `app/Models/Shift.php` | Add `shiftBikers()` hasMany, enum casts, BR-01 model event, state guard |
| Model | `app/Models/ShiftBiker.php` | Add `payment()` hasOne relationship |
| Test | `tests/Unit/PayoutServiceTest.php` | Fix 2 incorrect expected values: `100089990.00` → `100899990.00` |
| Test | `tests/Feature/Payout/PayoutIntegrationTest.php` | Verify pass after migration change; add explicit status/started_at if needed |

### Do NOT Modify

| File | Reason |
|------|--------|
| `app/Services/PayoutService.php` | Already implemented and validated for BR-03 |
| `app/Services/RevenueService.php` | Already implemented and correct |
| `database/migrations/2026_05_14_000001_create_restaurants_table.php` | Already matches plan |
| `database/migrations/2026_05_14_000002_create_bikers_table.php` | Already matches plan |
| `database/migrations/2026_05_14_000004_create_shift_bikers_table.php` | Already matches plan |

---

## 9. Edge Cases

1. **Zero trips (BR-03 critical):** `PayoutService::calculate(any, any, 0)` returns `'0.00'` — already verified.

2. **Zero base fee:** `PayoutService::calculate('0.00', '10.00', 5)` → `'50.00'` — already verified.

3. **Zero biker rate:** `PayoutService::calculate('25.00', '0.00', 5)` → `'25.00'` — already verified.

4. **All zeroes:** Both zero trips and positive trips scenarios — already verified.

5. **Large numbers precision:** `999999.99 + 99999.99 × 999 = 100,899,990.00` — BCMath handles DECIMAL(12,2) boundary. Test expected value needs fix.

6. **Negative trips_count:** Rejected by PayoutService `InvalidArgumentException` — already verified. DB enforces `UNSIGNED INT`.

7. **Negative rates:** Caller validates. Model validation must ensure `>= 0`.

8. **Revenue can be negative:** Valid business scenario (loss-leading). RevenueService handles correctly.

9. **Multiple bikers on same shift:** Independent `shift_biker` rows, independent calculations.

10. **Same biker on multiple shifts:** Different snapshotted rates per shift — unaffected by current rate changes.

11. **Concurrent trip increments:** Schema supports via `UNSIGNED INT` + Eloquent `increment()`. Not implemented in Phase 1.

12. **Duplicate PIX key for same biker:** Rejected by unique index `(biker_id, key_type, key_value)`.

13. **Payment without approval:** `released_at` NULL until admin approves. Model logic enforces transition constraint.

14. **Duplicate transaction_ref (BR-06):** Rejected by unique index on `payment_audit_logs.transaction_ref`.

15. **Draft → Closed skip (AC-38a):** Shift cannot jump from `draft` to `closed`/`approved`/`paid` — must go through `open` first.

16. **Workflow type change in draft (AC-36a):** Freely editable while `status = draft`.

17. **Workflow type change after draft (AC-37, AC-37a):** `WorkflowLockedException` thrown for `open`, `closed`, `approved`, `paid`.

18. **Non-workflow update on started shift (AC-38):** Updating `status` or other attributes on a non-draft shift does NOT throw — only `workflow_type` changes are blocked.

---

## 10. Acceptance Criteria

### Schema & Migrations

- [ ] AC-01: `php artisan migrate:fresh` completes without errors, creating all 7 tables (restaurants, bikers, pix_keys, shifts, shift_bikers, payments, payment_audit_logs).
- [ ] AC-02: `restaurants` table has `rate_per_trip` as `DECIMAL(12,2)` with default `'0.00'`.
- [ ] AC-03: `bikers` table has `rate_per_trip` and `base_fee` as `DECIMAL(12,2)` with default `'0.00'`, and `phone` as `UNIQUE`.
- [ ] AC-04: `pix_keys` table has `is_verified` boolean, `verified_at` nullable timestamp, unique index on `(biker_id, key_type, key_value)`.
- [ ] AC-05: `shifts` table has `workflow_type` as `VARCHAR(20)` default `'live_tick'`, `status` as `VARCHAR(20)` default `'draft'`, `restaurant_rate` as `DECIMAL(12,2)`, `started_at` as **nullable** TIMESTAMP, `created_by` as nullable BIGINT UNSIGNED, indexes on `(restaurant_id, status)` and `(status)`.
- [ ] AC-06: `shift_bikers` table has `trips_count` as `UNSIGNED INT` default 0, `biker_rate` and `base_fee` as `DECIMAL(12,2)`, unique index on `(shift_id, biker_id)`.
- [ ] AC-07: `payments` table has `amount` as `DECIMAL(12,2)`, `status` as `VARCHAR(20)` default `'pending'`, `released_by` nullable, `released_at` and `paid_at` as nullable timestamps, indexes on `shift_biker_id` and `status`.
- [ ] AC-08: `payment_audit_logs` table has `transaction_ref` as `VARCHAR(255) UNIQUE`, `action` as `VARCHAR(20)`, `payload` as JSON, index on `payment_id`.

### Models & Relationships

- [ ] AC-09: `Restaurant` model has `hasMany(Shift::class)` relationship.
- [ ] AC-10: `Biker` model has `hasMany(PixKey::class)` and `hasMany(ShiftBiker::class)` relationships.
- [ ] AC-11: `PixKey` model has `belongsTo(Biker::class)` relationship.
- [ ] AC-12: `Shift` model has `belongsTo(Restaurant::class)` and `hasMany(ShiftBiker::class)` relationships.
- [ ] AC-13: `ShiftBiker` model has `belongsTo(Shift::class)`, `belongsTo(Biker::class)`, and `hasOne(Payment::class)` relationships.
- [ ] AC-14: `Payment` model has `belongsTo(ShiftBiker::class)` and `hasMany(PaymentAuditLog::class)` relationships.
- [ ] AC-15: `PaymentAuditLog` model has `belongsTo(Payment::class)` relationship.
- [ ] AC-16: All models with financial fields cast them as `decimal:2` in the `casts()` method.
- [ ] AC-17: All models have `$fillable` arrays defined (no `$guarded = []`).

### Enums

- [ ] AC-18: `ShiftStatus` backed enum exists with values: `Draft='draft'`, `Open='open'`, `Closed='closed'`, `Approved='approved'`, `Paid='paid'`.
- [ ] AC-19: `WorkflowType` backed enum exists with values: `LiveTick='live_tick'`, `ManualEntry='manual_entry'`.
- [ ] AC-20: `PaymentStatus` backed enum exists with values: `Pending='pending'`, `Processing='processing'`, `Paid='paid'`, `Failed='failed'`.
- [ ] AC-21: `PixKeyType` backed enum exists with values: `Cpf='cpf'`, `Phone='phone'`, `Email='email'`, `Random='random'`.
- [ ] AC-22: `PaymentAuditAction` backed enum exists with values: `Create='create'`, `Release='release'`, `Attempt='attempt'`, `Retry='retry'`, `Fail='fail'`, `Succeed='succeed'`.

### PayoutService (BR-03) — Already Verified, Do NOT Overwrite

- [ ] AC-23: `PayoutService::calculate('25.00', '10.00', 0)` returns `'0.00'`.
- [ ] AC-24: `PayoutService::calculate('25.00', '10.00', 1)` returns `'35.00'`.
- [ ] AC-25: `PayoutService::calculate('25.00', '10.00', 5)` returns `'75.00'`.
- [ ] AC-26: `PayoutService::calculate('25.00', '10.00', 100)` returns `'1025.00'`.
- [ ] AC-27: `PayoutService::calculate('0.00', '10.00', 3)` returns `'30.00'`.
- [ ] AC-28: `PayoutService::calculate('25.00', '12.50', 7)` returns `'112.50'`.
- [ ] AC-29: `PayoutService::calculate('0.00', '0.00', 0)` returns `'0.00'`.
- [ ] AC-30: All PayoutService return values are PHP strings, not floats.

### RevenueService — Already Verified

- [ ] AC-31: `RevenueService::calculate('15.00', 5, '75.00')` returns `'0.00'` (break-even).
- [ ] AC-32: `RevenueService::calculate('20.00', 5, '75.00')` returns `'25.00'` (profit).
- [ ] AC-33: `RevenueService::calculate('10.00', 5, '75.00')` returns `'-25.00'` (loss).
- [ ] AC-34: `RevenueService::calculate('15.00', 0, '0.00')` returns `'0.00'` (zero trips).
- [ ] AC-35: All RevenueService return values are PHP strings, not floats.

### BR-01: Workflow Locking (Option C — Draft with pre-selected workflow)

- [ ] AC-36: Creating a new `Shift` with `workflow_type = 'live_tick'` and default status `draft` succeeds.
- [ ] AC-36a: A `Shift` in `draft` status can have its `workflow_type` changed freely (no exception).
- [ ] AC-36b: Transitioning a `Shift` from `draft` to `open` sets `started_at` to the current timestamp.
- [ ] AC-37: A `Shift` with status `open` throws `WorkflowLockedException` when attempting to change `workflow_type`.
- [ ] AC-37a: A `Shift` with status `closed`, `approved`, or `paid` also throws `WorkflowLockedException` when attempting to change `workflow_type`.
- [ ] AC-38: Updating other attributes on a non-draft `Shift` (e.g., `status`) does NOT throw — only `workflow_type` changes blocked.
- [ ] AC-38a: A `Shift` in `draft` status cannot transition directly to `closed`, `approved`, or `paid` — must go through `open` first.

### Factories

- [ ] AC-39: `Restaurant::factory()->create()` produces a valid restaurant with `rate_per_trip` matching `/^\d+\.\d{2}$/`.
- [ ] AC-40: `Biker::factory()->create()` produces a valid biker with explicit `rate_per_trip` and `base_fee` as strings (no random floats).
- [ ] AC-41: All 7 factories produce valid records that pass database constraints.

### Test Suite Health

- [ ] AC-T1: `php artisan test` returns **0 failures** after all changes (currently 2 failures from test bug).
- [ ] AC-T2: The 2 existing failing tests (`payout formula via data provider with data set "large numbers"` and `calculate large numbers no precision loss`) pass after expected value correction.

---

## 11. Execution Order (Developer Instructions)

The Developer should implement in this order to minimize friction:

### Step 1: Fix test bugs (5 min)
- Fix `tests/Unit/PayoutServiceTest.php`: correct `100089990.00` → `100899990.00` in 2 places
- Run `php artisan test` → verify all 75+ tests pass

### Step 2: Create enums (15 min)
- Create `app/Enums/` directory
- Create all 5 backed enums (ShiftStatus, WorkflowType, PaymentStatus, PixKeyType, PaymentAuditAction)
- Run `php artisan test` → should still pass

### Step 3: Create exception (5 min)
- Create `app/Exceptions/WorkflowLockedException.php`

### Step 4: Fix shifts migration + create 3 new migrations (20 min)
- Modify `database/migrations/2026_05_14_000003_create_shifts_table.php`
- Create `2026_05_14_000005_create_pix_keys_table.php`
- Create `2026_05_14_000006_create_payments_table.php`
- Create `2026_05_14_000007_create_payment_audit_logs_table.php`
- Run `php artisan migrate:fresh` → verify all 7 tables created

### Step 5: Update existing models (20 min)
- Add relationships to Restaurant, Biker, Shift, ShiftBiker
- Add enum casts to Shift
- Add BR-01 enforcement (saving event) to Shift
- Add state transition guard to Shift

### Step 6: Create new models (15 min)
- PixKey, Payment, PaymentAuditLog
- All with fillable, casts, relationships

### Step 7: Update PayoutIntegrationTest (10 min)
- Verify existing tests pass after migration changes
- Update any tests that break due to draft default

### Step 8: Create factories (20 min)
- All 7 factories with explicit financial defaults
- Shift factory with named states

### Step 9: Create model tests (30 min)
- RestaurantModelTest, BikerModelTest, ShiftModelTest, ShiftBikerModelTest
- PaymentModelTest, PaymentAuditLogModelTest, PixKeyModelTest
- FactoryTest

### Step 10: Final validation
- Run `php artisan migrate:fresh && php artisan test` → **0 failures**
- Verify all AC-01 through AC-41 pass

---

## 12. Security Considerations

- **Authorization:** N/A for Phase 1 — no routes or controllers.
- **Input Validation:** N/A — no HTTP endpoints. Services validate typed parameters.
- **Container Compliance:** All code lives within `/workspaces/bikerflow`. No external access.
- **Financial Safety:** All financial columns `DECIMAL(12,2)`. All calculations BCMath scale 2. No float arithmetic on money. `transaction_ref` unique prevents double-billing (BR-06).
- **Mass Assignment:** All models use explicit `$fillable`. No `$guarded = []`.
