<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';
}
