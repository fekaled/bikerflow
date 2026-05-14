<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShiftBiker extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'biker_id',
        'trips_count',
        'biker_rate',
        'base_fee',
    ];

    protected function casts(): array
    {
        return [
            'biker_rate' => 'decimal:2',
            'base_fee' => 'decimal:2',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function biker(): BelongsTo
    {
        return $this->belongsTo(Biker::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
