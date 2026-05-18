<?php

namespace App\Contracts;

class PaymentResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transaction_id = null,
        public readonly string $status = 'queued',
        public readonly ?string $error_code = null,
        public readonly ?string $error_message = null,
    ) {}
}
