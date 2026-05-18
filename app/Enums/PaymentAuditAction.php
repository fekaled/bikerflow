<?php

namespace App\Enums;

enum PaymentAuditAction: string
{
    case Create = 'create';
    case Release = 'release';
    case Attempt = 'attempt';
    case Retry = 'retry';
    case Fail = 'fail';
    case Succeed = 'succeed';
    case VerifyPix = 'verify_pix';
}
