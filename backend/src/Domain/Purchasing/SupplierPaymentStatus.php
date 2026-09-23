<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

enum SupplierPaymentStatus: string
{
    case Recorded = 'RECORDED';
    case PartiallyAllocated = 'PARTIALLY_ALLOCATED';
    case FullyAllocated = 'FULLY_ALLOCATED';
}
