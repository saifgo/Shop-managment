<?php

declare(strict_types=1);

namespace App\Domain\Production;

enum ProductionPriority: string
{
    case Normal = 'NORMAL';
    case High = 'HIGH';
    case Urgent = 'URGENT';
}
