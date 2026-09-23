<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

enum StockMovementType: string
{
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case ProductionReceipt = 'PRODUCTION_RECEIPT';
    case SaleReservation = 'SALE_RESERVATION';
    case SaleShipment = 'SALE_SHIPMENT';
    case ReturnReceipt = 'RETURN_RECEIPT';
    case Damage = 'DAMAGE';
    case Adjustment = 'ADJUSTMENT';
    case TransferOut = 'TRANSFER_OUT';
    case TransferIn = 'TRANSFER_IN';
}
