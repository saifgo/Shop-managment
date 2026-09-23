<?php

declare(strict_types=1);

namespace App\Domain\Payments;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';
    case Check = 'CHECK';
    case Card = 'CARD';
}
