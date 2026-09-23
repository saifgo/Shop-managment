<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_items')]
class PurchaseOrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(name: 'quantity_ordered', type: 'decimal', precision: 19, scale: 4)]
    private string $quantityOrdered;

    #[ORM\Column(name: 'quantity_received', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $quantityReceived = '0.0000';

    #[ORM\Column(name: 'unit_price_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $unitPriceAmount;

    #[ORM\Column(name: 'unit_price_currency', length: 3)]
    private string $unitPriceCurrency;

    public function __construct(
        EntityId $id,
        PurchaseOrder $purchaseOrder,
        ProductVariant $variant,
        Quantity $quantityOrdered,
        Money $unitPrice,
    ) {
        $this->id = $id->toString();
        $this->purchaseOrder = $purchaseOrder;
        $this->variant = $variant;
        $this->quantityOrdered = $quantityOrdered->amount();
        $this->unitPriceAmount = $unitPrice->amount();
        $this->unitPriceCurrency = $unitPrice->currency();
        $purchaseOrder->addItem($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getQuantityOrdered(): Quantity
    {
        return Quantity::of($this->quantityOrdered);
    }

    public function getQuantityReceived(): Quantity
    {
        return Quantity::of($this->quantityReceived);
    }

    public function getRemainingReceivableQuantity(): Quantity
    {
        return $this->getQuantityOrdered()->subtract($this->getQuantityReceived());
    }

    public function getUnitPrice(): Money
    {
        return Money::of($this->unitPriceAmount, $this->unitPriceCurrency);
    }

    public function getLineTotal(): Money
    {
        return Money::of(
            bcmul($this->unitPriceAmount, $this->quantityOrdered, 4),
            $this->unitPriceCurrency,
        );
    }

    public function recordReceipt(Quantity $quantity): void
    {
        $newReceived = $this->getQuantityReceived()->add($quantity);

        if ($newReceived->compare($this->getQuantityOrdered()) > 0) {
            throw new \DomainException('Cannot receive more than ordered quantity.');
        }

        $this->quantityReceived = $newReceived->amount();
    }
}
