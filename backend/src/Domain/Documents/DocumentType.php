<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentType: string
{
    case Quote = 'QUOTE';
    case Proforma = 'PROFORMA';
    case SalesOrder = 'SALES_ORDER';
    case DeliveryNote = 'DELIVERY_NOTE';
    case GoodsIssue = 'GOODS_ISSUE';
    case Invoice = 'INVOICE';
    case CreditNote = 'CREDIT_NOTE';

    public function numberPrefix(): string
    {
        return match ($this) {
            self::Quote => 'QUO',
            self::Proforma => 'PRO',
            self::SalesOrder => 'SO',
            self::DeliveryNote => 'DN',
            self::GoodsIssue => 'GI',
            self::Invoice => 'INV',
            self::CreditNote => 'CN',
        };
    }

    /** Title printed on the generated document, e.g. "Facture N° INV-2026-000001". */
    public function printedTitle(): string
    {
        return match ($this) {
            self::Quote => 'Devis',
            self::Proforma => 'Facture proforma',
            self::SalesOrder => 'Bon de commande',
            self::DeliveryNote => 'Bon de livraison',
            self::GoodsIssue => 'Bon de sortie',
            self::Invoice => 'Facture',
            self::CreditNote => 'Avoir',
        };
    }

    /**
     * Types an admin may create by hand. Credit notes are excluded because they
     * must reference an issued invoice through the credit-note workflow.
     *
     * @return list<self>
     */
    public static function manuallyCreatable(): array
    {
        return [
            self::Quote,
            self::Proforma,
            self::SalesOrder,
            self::DeliveryNote,
            self::GoodsIssue,
            self::Invoice,
        ];
    }
}
