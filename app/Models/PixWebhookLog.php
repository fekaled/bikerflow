<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pix Webhook Log — Phase 4C model stub.
 *
 * Minimal implementation to unblock Phase 4B test suite.
 * Full implementation coming in Phase 4C.
 *
 * @property int $id
 * @property string|null $gateway_transaction_id
 * @property string|null $status
 * @property array|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PixWebhookLog extends Model
{
    protected $table = 'pix_webhook_logs';

    protected $fillable = [
        'gateway_transaction_id',
        'status',
        'payload',
        'error_message',
        'ip_address',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
