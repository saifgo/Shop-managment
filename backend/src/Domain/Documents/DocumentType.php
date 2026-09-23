<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentType: string
{
    case Quote = 'QUOTE';
    case SalesOrder = 'SALES_ORDER';
    case DeliveryNote = 'DELIVERY_NOTE';
    case Invoice = 'INVOICE';
    case CreditNote = 'CREDIT_NOTE';

    public function numberPrefix(): string
    {
        return match ($this) {
            self::Quote => 'QUO',
            self::SalesOrder => 'SO',
            self::DeliveryNote => 'DN',
            self::Invoice => 'INV',
            self::CreditNote => 'CN',
        };
    }
}
