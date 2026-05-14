<?php

/**
 * ADR-001: Shift lifecycle states — draft → open → closed → approved → paid.
 *
 * @see docs/adr/001-core-payout-schema.md
 */

namespace App\Enums;

enum ShiftStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Approved = 'approved';
    case Paid = 'paid';
}
