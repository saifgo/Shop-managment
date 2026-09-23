<?php

declare(strict_types=1);

namespace App\Domain\Returns;

enum ReturnItemCondition: string
{
    case Sellable = 'SELLABLE';
    case Damaged = 'DAMAGED';
    case DamagedInTransit = 'DAMAGED_IN_TRANSIT';
}
