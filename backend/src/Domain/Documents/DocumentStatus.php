<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
}
