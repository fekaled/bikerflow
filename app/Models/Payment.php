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
}
