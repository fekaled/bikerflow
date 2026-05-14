<?php

namespace App\Models;

use App\Enums\PaymentAuditAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'action',
        'transaction_ref',
        'payload',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'action' => PaymentAuditAction::class,
            'payload' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
