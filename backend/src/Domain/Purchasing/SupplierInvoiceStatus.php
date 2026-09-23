<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

enum SupplierInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';
}
