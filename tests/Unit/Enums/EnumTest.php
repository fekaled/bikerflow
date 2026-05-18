<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\PixKeyType;
use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use PHPUnit\Framework\TestCase;

/**
 * Enum Coverage Tests
 *
 * Validates all 5 backed enums exist with correct string values.
 * AC-18 through AC-22.
 */
class EnumTest extends TestCase
{
    // ========================================================================
    // AC-18: ShiftStatus backed enum
    // ========================================================================

    /**
     * AC-18: ShiftStatus enum exists with exactly the required values.
     */
    public function test_shift_status_enum_has_all_required_cases(): void
    {
        $cases = ShiftStatus::cases();

        $this->assertCount(5, $cases,
            'AC-18: ShiftStatus must have exactly 5 cases: Draft, Open, Closed, Approved, Paid');
    }

    /**
     * AC-18: Verify each ShiftStatus case has the correct string value.
     */
    public function test_shift_status_values_are_correct(): void
    {
        $this->assertSame('draft', ShiftStatus::Draft->value,
            'AC-18: ShiftStatus::Draft must be "draft"');
        $this->assertSame('open', ShiftStatus::Open->value,
            'AC-18: ShiftStatus::Open must be "open"');
        $this->assertSame('closed', ShiftStatus::Closed->value,
            'AC-18: ShiftStatus::Closed must be "closed"');
        $this->assertSame('approved', ShiftStatus::Approved->value,
            'AC-18: ShiftStatus::Approved must be "approved"');
        $this->assertSame('paid', ShiftStatus::Paid->value,
            'AC-18: ShiftStatus::Paid must be "paid"');
    }

    /**
     * AC-18: ShiftStatus is a backed string enum.
     */
    public function test_shift_status_is_backed_string_enum(): void
    {
        $reflection = new \ReflectionEnum(ShiftStatus::class);
        $this->assertTrue($reflection->isBacked(),
            'AC-18: ShiftStatus must be a backed enum');

        $backingType = $reflection->getBackingType();
        $this->assertNotNull($backingType,
            'AC-18: ShiftStatus must have a backing type');
        $this->assertSame('string', $backingType->getName(),
            'AC-18: ShiftStatus backing type must be string');
    }

    /**
     * AC-18: ShiftStatus::from() works correctly for all valid values.
     */
    public function test_shift_status_from_returns_correct_case(): void
    {
        $this->assertSame(ShiftStatus::Draft, ShiftStatus::from('draft'));
        $this->assertSame(ShiftStatus::Open, ShiftStatus::from('open'));
        $this->assertSame(ShiftStatus::Closed, ShiftStatus::from('closed'));
        $this->assertSame(ShiftStatus::Approved, ShiftStatus::from('approved'));
        $this->assertSame(ShiftStatus::Paid, ShiftStatus::from('paid'));
    }

    // ========================================================================
    // AC-19: WorkflowType backed enum
    // ========================================================================

    /**
     * AC-19: WorkflowType enum exists with exactly 2 values.
     */
    public function test_workflow_type_enum_has_all_required_cases(): void
    {
        $cases = WorkflowType::cases();

        $this->assertCount(2, $cases,
            'AC-19: WorkflowType must have exactly 2 cases: LiveTick, ManualEntry');
    }

    /**
     * AC-19: Verify each WorkflowType case has the correct string value.
     */
    public function test_workflow_type_values_are_correct(): void
    {
        $this->assertSame('live_tick', WorkflowType::LiveTick->value,
            'AC-19: WorkflowType::LiveTick must be "live_tick"');
        $this->assertSame('manual_entry', WorkflowType::ManualEntry->value,
            'AC-19: WorkflowType::ManualEntry must be "manual_entry"');
    }

    /**
     * AC-19: WorkflowType is a backed string enum.
     */
    public function test_workflow_type_is_backed_string_enum(): void
    {
        $reflection = new \ReflectionEnum(WorkflowType::class);
        $this->assertTrue($reflection->isBacked(),
            'AC-19: WorkflowType must be a backed enum');
        $backingType = $reflection->getBackingType();
        $this->assertSame('string', $backingType->getName(),
            'AC-19: WorkflowType backing type must be string');
    }

    // ========================================================================
    // AC-20: PaymentStatus backed enum
    // ========================================================================

    /**
     * AC-20: PaymentStatus enum exists with exactly 4 values.
     */
    public function test_payment_status_enum_has_all_required_cases(): void
    {
        $cases = PaymentStatus::cases();

        $this->assertCount(4, $cases,
            'AC-20: PaymentStatus must have exactly 4 cases: Pending, Processing, Paid, Failed');
    }

    /**
     * AC-20: Verify each PaymentStatus case has the correct string value.
     */
    public function test_payment_status_values_are_correct(): void
    {
        $this->assertSame('pending', PaymentStatus::Pending->value,
            'AC-20: PaymentStatus::Pending must be "pending"');
        $this->assertSame('processing', PaymentStatus::Processing->value,
            'AC-20: PaymentStatus::Processing must be "processing"');
        $this->assertSame('paid', PaymentStatus::Paid->value,
            'AC-20: PaymentStatus::Paid must be "paid"');
        $this->assertSame('failed', PaymentStatus::Failed->value,
            'AC-20: PaymentStatus::Failed must be "failed"');
    }

    /**
     * AC-20: PaymentStatus is a backed string enum.
     */
    public function test_payment_status_is_backed_string_enum(): void
    {
        $reflection = new \ReflectionEnum(PaymentStatus::class);
        $this->assertTrue($reflection->isBacked(),
            'AC-20: PaymentStatus must be a backed enum');
        $backingType = $reflection->getBackingType();
        $this->assertSame('string', $backingType->getName(),
            'AC-20: PaymentStatus backing type must be string');
    }

    // ========================================================================
    // AC-21: PixKeyType backed enum
    // ========================================================================

    /**
     * AC-21: PixKeyType enum exists with exactly 4 values.
     */
    public function test_pix_key_type_enum_has_all_required_cases(): void
    {
        $cases = PixKeyType::cases();

        $this->assertCount(4, $cases,
            'AC-21: PixKeyType must have exactly 4 cases: Cpf, Phone, Email, Random');
    }

    /**
     * AC-21: Verify each PixKeyType case has the correct string value.
     */
    public function test_pix_key_type_values_are_correct(): void
    {
        $this->assertSame('cpf', PixKeyType::Cpf->value,
            'AC-21: PixKeyType::Cpf must be "cpf"');
        $this->assertSame('phone', PixKeyType::Phone->value,
            'AC-21: PixKeyType::Phone must be "phone"');
        $this->assertSame('email', PixKeyType::Email->value,
            'AC-21: PixKeyType::Email must be "email"');
        $this->assertSame('random', PixKeyType::Random->value,
            'AC-21: PixKeyType::Random must be "random"');
    }

    /**
     * AC-21: PixKeyType is a backed string enum.
     */
    public function test_pix_key_type_is_backed_string_enum(): void
    {
        $reflection = new \ReflectionEnum(PixKeyType::class);
        $this->assertTrue($reflection->isBacked(),
            'AC-21: PixKeyType must be a backed enum');
        $backingType = $reflection->getBackingType();
        $this->assertSame('string', $backingType->getName(),
            'AC-21: PixKeyType backing type must be string');
    }

    // ========================================================================
    // AC-22: PaymentAuditAction backed enum
    // ========================================================================

    /**
     * AC-22: PaymentAuditAction enum exists with exactly 6 values.
     */
    public function test_payment_audit_action_enum_has_all_required_cases(): void
    {
        $cases = PaymentAuditAction::cases();

        $this->assertCount(7, $cases,
            'PaymentAuditAction must have exactly 7 cases: Create, Release, Attempt, Retry, Fail, Succeed, VerifyPix');
    }

    /**
     * AC-22: Verify each PaymentAuditAction case has the correct string value.
     */
    public function test_payment_audit_action_values_are_correct(): void
    {
        $this->assertSame('create', PaymentAuditAction::Create->value,
            'AC-22: PaymentAuditAction::Create must be "create"');
        $this->assertSame('release', PaymentAuditAction::Release->value,
            'AC-22: PaymentAuditAction::Release must be "release"');
        $this->assertSame('attempt', PaymentAuditAction::Attempt->value,
            'AC-22: PaymentAuditAction::Attempt must be "attempt"');
        $this->assertSame('retry', PaymentAuditAction::Retry->value,
            'AC-22: PaymentAuditAction::Retry must be "retry"');
        $this->assertSame('fail', PaymentAuditAction::Fail->value,
            'AC-22: PaymentAuditAction::Fail must be "fail"');
        $this->assertSame('succeed', PaymentAuditAction::Succeed->value,
            'AC-22: PaymentAuditAction::Succeed must be "succeed"');
    }

    /**
     * AC-22: PaymentAuditAction is a backed string enum.
     */
    public function test_payment_audit_action_is_backed_string_enum(): void
    {
        $reflection = new \ReflectionEnum(PaymentAuditAction::class);
        $this->assertTrue($reflection->isBacked(),
            'AC-22: PaymentAuditAction must be a backed enum');
        $backingType = $reflection->getBackingType();
        $this->assertSame('string', $backingType->getName(),
            'AC-22: PaymentAuditAction backing type must be string');
    }
}
