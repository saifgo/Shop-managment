<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

enum ReservationStatus: string
{
    case Active = 'ACTIVE';
    case Released = 'RELEASED';
    case Fulfilled = 'FULFILLED';
}
