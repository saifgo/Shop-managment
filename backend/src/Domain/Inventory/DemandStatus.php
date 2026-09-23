<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

enum DemandStatus: string
{
    case Open = 'OPEN';
    case PartiallyFulfilled = 'PARTIALLY_FULFILLED';
    case Fulfilled = 'FULFILLED';
    case Cancelled = 'CANCELLED';
}
