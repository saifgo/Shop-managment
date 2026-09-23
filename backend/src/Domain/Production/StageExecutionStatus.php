<?php

declare(strict_types=1);

namespace App\Domain\Production;

enum StageExecutionStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Skipped = 'SKIPPED';
}
