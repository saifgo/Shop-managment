<?php

declare(strict_types=1);

namespace App\Domain\Returns;

enum ReturnStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Received = 'RECEIVED';
    case Inspected = 'INSPECTED';
    case Resolved = 'RESOLVED';
}
