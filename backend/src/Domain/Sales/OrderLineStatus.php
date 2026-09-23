<?php

declare(strict_types=1);

namespace App\Domain\Sales;

enum OrderLineStatus: string
{
    case Unallocated = 'UNALLOCATED';
    case Reserved = 'RESERVED';
    case Backordered = 'BACKORDERED';
    case Ready = 'READY';
    case Delivered = 'DELIVERED';
}
