# BikerFlow — Coding Standards & Conventions

## Framework Conventions

### File Naming

| Type | Convention | Example |
|------|-----------|---------|
| Model | Singular PascalCase | `Restaurant.php`, `Shift.php` |
| Controller | Singular PascalCase + Controller | `ShiftController.php` |
| Migration | Laravel auto-generated timestamp | `2026_05_13_000000_create_shifts_table.php` |
| Service | Singular PascalCase + Service | `PayoutService.php` |
| Form Request | Descriptive PascalCase + Request | `StartShiftRequest.php` |
| Exception | Descriptive PascalCase + Exception | `WorkflowLockedException.php` |

### Directory Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── ShiftController.php
│   │   ├── PaymentController.php
│   │   └── BikerController.php
│   ├── Middleware/
│   │   └── EnsureWorkflowIsLocked.php
│   └── Requests/
│       ├── StartShiftRequest.php
│       └── CloseShiftRequest.php
├── Models/
│   ├── Restaurant.php
│   ├── Biker.php
│   ├── Shift.php
│   ├── Trip.php
│   ├── Payment.php
│   └── PixKey.php
├── Services/
│   ├── PayoutService.php
│   ├── RevenueService.php
│   └── ShiftWorkflowService.php
├── Exceptions/
│   ├── ShiftAlreadyClosedException.php
│   ├── WorkflowLockedException.php
│   └── PaymentFailedException.php
└── Enums/
    ├── ShiftStatus.php
    ├── WorkflowType.php
    └── PaymentStatus.php
```

## Model Conventions

### Required Components

Every model must have:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'workflow_type',
        'status',
        'base_fee',
        'biker_rate',
        'restaurant_rate',
        'trips_count',
        'started_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'base_fee' => 'decimal:2',
            'biker_rate' => 'decimal:2',
            'restaurant_rate' => 'decimal:2',
            'trips_count' => 'integer',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
```

### Relationship Conventions

```php
// Shift belongs to a Restaurant
public function restaurant(): BelongsTo
{
    return $this->belongsTo(Restaurant::class);
}

// Shift has many Trips
public function trips(): HasMany
{
    return $this->hasMany(Trip::class);
}

// Shift has many Payments (one per biker)
public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}
```

### Query Scopes

```php
// Status scopes for common queries
public function scopeOpen($query)
{
    return $query->where('status', 'open');
}

public function scopeClosed($query)
{
    return $query->where('status', 'closed');
}
```

## Service Conventions

### Structure

```php
<?php

namespace App\Services;

use App\Exceptions\WorkflowLockedException;
use App\Models\Shift;

class PayoutService
{
    /**
     * Calculate the biker payout for a shift.
     *
     * Business Rule BR-03: Payout Formula
     * - If trips_count = 0 → Payout = 0.00
     * - If trips_count > 0 → Payout = base_fee + (biker_rate × trips_count)
     *
     * All calculations use BCMath for precision.
     * Currency: BRL
     */
    public function calculate(string $baseFee, string $bikerRate, int $tripsCount): string
    {
        if ($tripsCount === 0) {
            return '0.00';
        }

        return bcadd(
            $baseFee,
            bcmul($bikerRate, (string) $tripsCount, 2),
            2
        );
    }
}
```

### Rules for Services

- **Pure methods where possible.** Services should accept parameters and return results, not reach into global state.
- **Type-hint all parameters.** Use `string` for monetary values (BCMath compatibility).
- **Docblock must reference the BR-XX rule.** This creates a traceable link from code to spec.
- **Throw domain exceptions** for business rule violations. Never return `null` or `false` to signal errors.
- **Never contain HTTP logic.** No `request()`, no `response()`, no `redirect()`. That's the controller's job.

## Controller Conventions

### Structure

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\ShiftAlreadyClosedException;
use App\Exceptions\WorkflowLockedException;
use App\Http\Requests\StartShiftRequest;
use App\Models\Shift;
use App\Services\ShiftWorkflowService;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftWorkflowService $workflowService,
    ) {}

    public function store(StartShiftRequest $request)
    {
        $shift = $this->workflowService->startShift(
            restaurantId: $request->validated('restaurant_id'),
            workflowType: $request->validated('workflow_type'),
        );

        return response()->json($shift, 201);
    }

    public function update(Request $request, Shift $shift)
    {
        try {
            $shift = $this->workflowService->updateShift($shift, $request->validated());
        } catch (WorkflowLockedException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ShiftAlreadyClosedException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($shift);
    }
}
```

### Rules for Controllers

- **Thin controllers.** Validate with Form Requests, delegate to Services, return responses.
- **Dependency-inject services.** Use constructor injection, never `app()`.
- **Catch domain exceptions.** Map them to appropriate HTTP status codes.
- **Never contain business logic.** If you're writing a calculation in a controller, it belongs in a service.

## Enum Conventions

Use PHP 8.4 backed enums for all status fields:

```php
<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Approved = 'approved';
    case Paid = 'paid';
}

enum WorkflowType: string
{
    case LiveTick = 'live_tick';
    case ManualEntry = 'manual_entry';
}

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Retry = 'retry';
}
```

## Migration Conventions

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->enum('workflow_type', ['live_tick', 'manual_entry']);
            $table->enum('status', ['open', 'closed', 'approved', 'paid'])->default('open');

            // Financial columns — always decimal(12,2)
            $table->decimal('base_fee', 12, 2)->default('0.00');
            $table->decimal('biker_rate', 12, 2);
            $table->decimal('restaurant_rate', 12, 2);

            // Counters
            $table->unsignedInteger('trips_count')->default(0);

            // Timestamps
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['restaurant_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
```

### Migration Rules

- Every migration must have a `down()` method.
- Foreign keys must use `constrained()` with explicit cascade behavior.
- Financial columns: always `$table->decimal(name, 12, 2)`.
- Status columns: use `->enum()` with explicit values, or `->string()` if using PHP enums.
- Indexes for every column used in `WHERE` or `ORDER BY` clauses.
- Never rename or drop columns without a separate migration.

## Factory Conventions

```php
<?php

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use App\Models\Restaurant;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'workflow_type' => WorkflowType::LiveTick->value,
            'status' => ShiftStatus::Open->value,
            'base_fee' => '25.00',
            'biker_rate' => '10.00',
            'restaurant_rate' => '15.00',
            'trips_count' => 0,
            'started_at' => now(),
            'closed_at' => null,
        ];
    }

    // Named states for common scenarios
    public function liveTick(): static
    {
        return $this->state(['workflow_type' => WorkflowType::LiveTick->value]);
    }

    public function manualEntry(): static
    {
        return $this->state(['workflow_type' => WorkflowType::ManualEntry->value]);
    }

    public function closed(): static
    {
        return $this->state([
            'status' => ShiftStatus::Closed->value,
            'trips_count' => 5,
            'closed_at' => now(),
        ]);
    }
}
```

### Factory Rules

- **Financial fields always have explicit defaults.** Never use `$faker->randomFloat()` for money.
- **Provide named states** for common scenarios (closed, paid, failed, etc.).
- **Use enum values** for status/type fields, not raw strings.

## Route Conventions

```php
// routes/web.php or routes/api.php

// Group by resource with middleware
Route::middleware(['auth'])->group(function () {
    Route::resource('shifts', ShiftController::class)->only([
        'store', 'show', 'update', 'destroy',
    ]);

    Route::post('shifts/{shift}/close', [ShiftController::class, 'close']);
    Route::post('shifts/{shift}/trips', [TripController::class, 'store']);
});

// Admin-only routes
Route::middleware(['auth', 'admin'])->group(function () {
    Route::post('payments/{payment}/release', [PaymentController::class, 'release']);
    Route::post('payments/{payment}/retry', [PaymentController::class, 'retry']);
});
```

## Code Generation Commands

```bash
# Create a model with migration, factory, and controller
docker exec devcontainer_app_1 php artisan make:model Shift -mfc

# Create a form request
docker exec devcontainer_app_1 php artisan make:request StartShiftRequest

# Create a controller (invokable)
docker exec devcontainer_app_1 php artisan make:controller CloseShiftController --invokable

# Create a migration
docker exec devcontainer_app_1 php artisan make:migration create_shifts_table

# Run migrations
docker exec devcontainer_app_1 php artisan migrate

# Rollback last migration
docker exec devcontainer_app_1 php artisan migrate:rollback

# Run Pint (code style fixer)
docker exec devcontainer_app_1 ./vendor/bin/pint

# Run Pint (dry-run check)
docker exec devcontainer_app_1 ./vendor/bin/pint --test
```
