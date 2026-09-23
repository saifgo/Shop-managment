<?php

declare(strict_types=1);

namespace App\Domain\Production;

enum ProductionStatus: string
{
    case Draft = 'DRAFT';
    case Planned = 'PLANNED';
    case InProgress = 'IN_PROGRESS';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
