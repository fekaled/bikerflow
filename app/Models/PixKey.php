<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PixKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'biker_id',
        'key_type',
        'key_value',
        'account_holder_name',
        'is_verified',
        'verified_at',
    ];

    protected $attributes = [
        'is_verified' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function biker(): BelongsTo
    {
        return $this->belongsTo(Biker::class);
    }
}
