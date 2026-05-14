# BikerFlow — Test Patterns & Conventions

## Framework: PHPUnit on Laravel 13

BikerFlow uses the default Laravel 13 testing setup:
- **PHPUnit** (not Pest)
- **SQLite in-memory** for test database
- **Feature tests** for HTTP + database interactions
- **Unit tests** for pure logic (calculations, services)

## Test Class Template

### Feature Test

```php
<?php

namespace Tests\Feature\Shifts;

use App\Models\Biker;
use App\Models\Restaurant;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance Criteria: AC-XX (from plan docs/plans/US-XX-...md)
 * Business Rules: BR-XX
 */
class CreateShiftTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::factory()->create([
            'rate_per_trip' => '15.00',
        ]);
    }

    // AC-01: Restaurant Manager can start a shift with Live Tick workflow
    public function test_start_shift_with_live_tick_workflow(): void
    {
        $response = $this->postJson('/api/shifts', [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'workflow_type' => 'live_tick',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('shifts', [
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
        ]);
    }

    // BR-01: Workflow type cannot be changed after shift starts
    public function test_workflow_type_locked_after_shift_start(): void
    {
        $shift = Shift::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'workflow_type' => 'live_tick',
            'status' => 'open',
        ]);

        $response = $this->patchJson("/api/shifts/{$shift->id}", [
            'workflow_type' => 'manual_entry',
        ]);

        $response->assertForbidden(); // or assertUnprocessable()
        $this->assertEquals('live_tick', $shift->fresh()->workflow_type);
    }
}
```

### Unit Test (Financial Logic)

```php
<?php

namespace Tests\Unit\Payout;

use App\Services\PayoutCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance Criteria: AC-XX
 * Business Rules: BR-03 (Payout Formula)
 */
class PayoutCalculationTest extends TestCase
{
    private PayoutCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PayoutCalculator();
    }

    // BR-03: Zero trips → zero payout
    public function test_payout_with_zero_trips_returns_zero(): void
    {
        $result = $this->calculator->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: 0,
        );

        $this->assertEquals('0.00', $result);
    }

    // BR-03: Standard payout
    public function test_payout_with_trips_includes_base_fee(): void
    {
        $result = $this->calculator->calculate(
            baseFee: '25.00',
            bikerRate: '10.00',
            tripsCount: 5,
        );

        // 25.00 + (10.00 × 5) = 75.00
        $this->assertEquals('75.00', $result);
    }

    // Data provider for systematic formula validation
    #[DataProvider('payoutDataProvider')]
    public function test_payout_formula(string $baseFee, string $rate, int $trips, string $expected): void
    {
        $result = $this->calculator->calculate(
            baseFee: $baseFee,
            bikerRate: $rate,
            tripsCount: $trips,
        );

        $this->assertEquals($expected, $result, "Failed for baseFee={$baseFee}, rate={$rate}, trips={$trips}");
    }

    public static function payoutDataProvider(): array
    {
        return [
            'zero trips'           => ['25.00', '10.00', 0,   '0.00'],
            'one trip'             => ['25.00', '10.00', 1,   '35.00'],
            'five trips'           => ['25.00', '10.00', 5,   '75.00'],
            'hundred trips'        => ['25.00', '10.00', 100, '1025.00'],
            'zero base fee'        => ['0.00',  '10.00', 3,   '30.00'],
            'decimal rate'         => ['25.00', '12.50', 7,   '112.50'],
            'zero base fee one trip' => ['0.00', '10.00', 1,  '10.00'],
        ];
    }
}
```

## Financial Assertion Rules

### Always use string comparison for money

```php
// ✅ CORRECT
$this->assertEquals('75.50', $payout);

// ❌ WRONG — float imprecision
$this->assertEquals(75.50, $payout);
$this->assertSame(75.50, (float) $payout);
```

### Use explicit factories with known values

```php
// ✅ CORRECT — deterministic financial values
Restaurant::factory()->create(['rate_per_trip' => '15.00']);
Biker::factory()->create(['rate_per_trip' => '10.00', 'base_fee' => '25.00']);

// ❌ WRONG — random values make assertions impossible
Restaurant::factory()->create(); // random rate_per_trip
```

### Test BCMath precision at scale

```php
public function test_payout_precision_with_large_numbers(): void
{
    $result = $this->calculator->calculate(
        baseFee: '999999.99',
        bikerRate: '99999.99',
        tripsCount: 999,
    );

    // Must be exact to 2 decimal places
    $this->assertEquals('100098989.00', $result);
}
```

## Mocking External APIs

Only mock external services (PIX banks). Never mock internal business logic.

```php
use Illuminate\Support\Facades\Http;

// Mock PIX bank API for payment tests
Http::fake([
    'api.starkbank.com/*' => Http::response([
        'status' => 'failed',
        'error' => 'invalid_pix_key',
    ], 422),
]);

// BR-04: Granular failure — Biker B succeeds even if Biker A fails
public function test_granular_payment_failure(): void
{
    Http::fake([
        '*/pix/pay/*' => function ($request) {
            $body = json_decode($request->body(), true);
            // Simulate failure for Biker A, success for Biker B
            if ($body['pix_key'] === 'biker-a-key') {
                return Http::response(['status' => 'failed'], 422);
            }
            return Http::response(['status' => 'success'], 200);
        },
    ]);

    // Biker A's payment fails
    // Biker B's payment succeeds
    // Verify Biker B is paid, Biker A is retry-able
}
```

## Audit Trail Testing (BR-06)

Every financial action must create an audit log entry:

```php
public function test_payment_retry_creates_unique_audit_entry(): void
{
    $payment = Payment::factory()->create(['status' => 'failed']);

    // First retry
    $this->postJson("/api/payments/{$payment->id}/retry");
    $this->assertDatabaseCount('payment_audit_logs', 1);

    // Second retry
    $this->postJson("/api/payments/{$payment->id}/retry");
    $this->assertDatabaseCount('payment_audit_logs', 2);

    // Verify each retry is unique
    $logs = PaymentAuditLog::where('payment_id', $payment->id)->get();
    $this->assertNotEquals($logs[0]->transaction_id, $logs[1]->transaction_id);
}
```

## Running Tests

```bash
# Full suite
docker exec devcontainer_app_1 php artisan test

# Specific file
docker exec devcontainer_app_1 php artisan test --filter=PayoutCalculationTest

# Specific test method
docker exec devcontainer_app_1 php artisan test --filter=test_payout_with_zero_trips

# With verbose output
docker exec devcontainer_app_1 php artisan test --filter=PayoutCalculationTest -v

# Only Unit tests
docker exec devcontainer_app_1 php artisan test --testsuite=Unit

# Only Feature tests
docker exec devcontainer_app_1 php artisan test --testsuite=Feature
```
