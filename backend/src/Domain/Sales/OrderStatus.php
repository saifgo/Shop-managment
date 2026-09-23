<?php

declare(strict_types=1);

namespace App\Domain\Sales;

enum OrderStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Confirmed = 'CONFIRMED';
    case PartiallyAllocated = 'PARTIALLY_ALLOCATED';
    case ReadyToDeliver = 'READY_TO_DELIVER';
    case PartiallyDelivered = 'PARTIALLY_DELIVERED';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';
}
