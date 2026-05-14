<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Biker extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'rate_per_trip',
        'base_fee',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'rate_per_trip' => 'decimal:2',
            'base_fee' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function pixKeys(): HasMany
    {
        return $this->hasMany(PixKey::class);
    }

    public function shiftBikers(): HasMany
    {
        return $this->hasMany(ShiftBiker::class);
    }
}
