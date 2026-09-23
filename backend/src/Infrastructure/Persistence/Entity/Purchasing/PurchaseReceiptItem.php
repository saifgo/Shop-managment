<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_receipt_items')]
class PurchaseReceiptItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: PurchaseReceipt::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'purchase_receipt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseReceipt $purchaseReceipt;

    #[ORM\ManyToOne(targetEntity: PurchaseOrderItem::class)]
    #[ORM\JoinColumn(name: 'purchase_order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PurchaseOrderItem $purchaseOrderItem;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    public function __construct(
        EntityId $id,
        PurchaseReceipt $purchaseReceipt,
        PurchaseOrderItem $purchaseOrderItem,
        Quantity $quantity,
    ) {
        $this->id = $id->toString();
        $this->purchaseReceipt = $purchaseReceipt;
        $this->purchaseOrderItem = $purchaseOrderItem;
        $this->quantity = $quantity->amount();
        $purchaseReceipt->addItem($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPurchaseReceipt(): PurchaseReceipt
    {
        return $this->purchaseReceipt;
    }

    public function getPurchaseOrderItem(): PurchaseOrderItem
    {
        return $this->purchaseOrderItem;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }
}
