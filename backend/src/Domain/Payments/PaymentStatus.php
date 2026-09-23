<?php

declare(strict_types=1);

namespace App\Domain\Payments;

enum PaymentStatus: string
{
    case Recorded = 'RECORDED';
    case PartiallyAllocated = 'PARTIALLY_ALLOCATED';
    case FullyAllocated = 'FULLY_ALLOCATED';
    case Cancelled = 'CANCELLED';
}
