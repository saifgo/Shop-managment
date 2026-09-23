<?php

declare(strict_types=1);

namespace App\Domain\Sales;

enum DeliveryStatus: string
{
    case ReadyToDeliver = 'READY_TO_DELIVER';
    case Packed = 'PACKED';
    case Dispatched = 'DISPATCHED';
    case InTransit = 'IN_TRANSIT';
    case Delivered = 'DELIVERED';
    case DeliveryException = 'DELIVERY_EXCEPTION';
}
