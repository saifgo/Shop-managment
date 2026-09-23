<?php

declare(strict_types=1);

namespace App\Domain\Finance;

enum FinanceCategoryType: string
{
    case Income = 'INCOME';
    case Expense = 'EXPENSE';
}
