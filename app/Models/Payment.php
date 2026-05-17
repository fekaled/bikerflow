<?php

/**
 * ADR-001: Payment entity — per-biker-per-shift with independent status (BR-04).
 *
 * @see docs/adr/001-core-payout-schema.md
 */

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_biker_id',
        'amount',
        'revenue',
        'status',
        'released_by',
        'released_at',
        'paid_at',
        'failed_at',
        'failure_reason',
        'retry_count',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'revenue' => 'decimal:2',
            'status' => PaymentStatus::class,
            'released_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }

    public function shiftBiker(): BelongsTo
    {
        return $this->belongsTo(ShiftBiker::class);
    }

    public function paymentAuditLogs(): HasMany
    {
        return $this->hasMany(PaymentAuditLog::class);
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * Check if this payment is eligible for release.
     * Requires: pending status, biker with verified PIX key, biker with User account.
     */
    public function isEligibleForRelease(): bool
    {
        if ($this->status !== PaymentStatus::Pending) {
            return false;
        }

        $biker = $this->shiftBiker->biker;

        if (! $biker->hasVerifiedPixKey()) {
            return false;
        }

        if (! $biker->hasUserAccount()) {
            return false;
        }

        return true;
    }

    /**
     * Check if this failed payment is eligible for retry.
     * BR-02: Re-checks PIX verification.
     * ADR-005 D4: Re-checks User account link.
     */
    public function isEligibleForRetry(): bool
    {
        if ($this->status !== PaymentStatus::Failed) {
            return false;
        }

        $biker = $this->shiftBiker->biker;

        if (! $biker->hasVerifiedPixKey()) {
            return false;
        }

        if (! $biker->hasUserAccount()) {
            return false;
        }

        return true;
    }
}
