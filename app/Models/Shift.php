<?php

/**
 * ADR-001: Core payout schema — Shift state machine & BR-01 workflow locking.
 *
 * @see docs/adr/001-core-payout-schema.md
 */

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use App\Exceptions\WorkflowLockedException;
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
        'restaurant_rate',
        'started_at',
        'closed_at',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'workflow_type' => 'live_tick',
    ];

    protected function casts(): array
    {
        return [
            'workflow_type' => WorkflowType::class,
            'status' => ShiftStatus::class,
            'restaurant_rate' => 'decimal:2',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function shiftBikers(): HasMany
    {
        return $this->hasMany(ShiftBiker::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Shift $shift) {
            // BR-01: Workflow type locking — only applies to existing (persisted) models
            // On initial creation, workflow_type and status are set together.
            if ($shift->exists && $shift->status !== ShiftStatus::Draft && $shift->isDirty('workflow_type')) {
                throw new WorkflowLockedException(
                    shift: $shift,
                    attemptedValue: $shift->workflow_type instanceof WorkflowType
                        ? $shift->workflow_type->value
                        : (string) $shift->workflow_type,
                );
            }

            // AC-38a: State transition guard — cannot skip from draft to non-open
            if ($shift->isDirty('status')) {
                $originalStatus = $shift->getOriginal('status');

                // getOriginal may return the string value when the attribute was cast
                $originalIsDraft = ($originalStatus instanceof ShiftStatus && $originalStatus === ShiftStatus::Draft)
                    || (is_string($originalStatus) && $originalStatus === 'draft')
                    || $originalStatus === null; // New model, default is draft

                $newStatus = $shift->status;

                // Only enforce transition guard on existing models (updates), not initial creation
                if ($shift->exists && $originalIsDraft
                    && $newStatus !== ShiftStatus::Open
                    && $newStatus !== ShiftStatus::Draft
                ) {
                    throw new \RuntimeException(
                        "Shift must transition to 'open' before '{$newStatus->value}'"
                    );
                }

                // AC-36b: When transitioning draft → open, set started_at
                if ($originalIsDraft && $newStatus === ShiftStatus::Open) {
                    $shift->started_at = now();
                }
            }
        });
    }
}
