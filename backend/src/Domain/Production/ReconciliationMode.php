<?php

declare(strict_types=1);

namespace App\Domain\Production;

enum ReconciliationMode: string
{
    case Strict = 'STRICT';
    case Flexible = 'FLEXIBLE';
    case Conversion = 'CONVERSION';
}
