<?php

declare(strict_types=1);

namespace App\Domain\Finance;

enum ScheduledTransactionType: string
{
    case Income = 'INCOME';
    case Expense = 'EXPENSE';
}
