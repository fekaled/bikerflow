<?php

namespace App\Contracts;

class VerifyKeyResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $account_holder_name = null,
        public readonly ?string $error_code = null,
        public readonly ?string $error_message = null,
    ) {}
}
