<?php

declare(strict_types=1);

namespace App\Domain\Returns;

enum ReturnResolution: string
{
    case Refund = 'REFUND';
    case Exchange = 'EXCHANGE';
    case Replacement = 'REPLACEMENT';
    case CreditNote = 'CREDIT_NOTE';
    case Rejected = 'REJECTED';
}
