<?php

declare(strict_types=1);

namespace App\Domain\Finance;

enum RecurrenceFrequency: string
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Yearly = 'YEARLY';
}
