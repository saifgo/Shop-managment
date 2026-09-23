<?php

declare(strict_types=1);

namespace App\Domain\Returns;

enum ReturnEventType: string
{
    case Created = 'CREATED';
    case Approved = 'APPROVED';
    case Received = 'RECEIVED';
    case Inspected = 'INSPECTED';
    case Resolved = 'RESOLVED';
    case Note = 'NOTE';
}
