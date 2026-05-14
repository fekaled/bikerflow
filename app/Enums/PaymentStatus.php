<?php

/**
 * ADR-001: Payment status lifecycle — pending → processing → paid/failed.
 *
 * @see docs/adr/001-core-payout-schema.md
 */

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
}
