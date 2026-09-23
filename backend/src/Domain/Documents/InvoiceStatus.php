<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Overdue = 'OVERDUE';
    case Cancelled = 'CANCELLED';
    case Credited = 'CREDITED';
}
