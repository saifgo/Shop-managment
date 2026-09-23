<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Sales\OrderLineStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(name: 'product_id', type: 'string', length: 26)]
    private string $productId;

    #[ORM\Column(name: 'product_name', length: 200)]
    private string $productName;

    #[ORM\Column(name: 'variant_name', length: 200)]
    private string $variantName;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(name: 'quantity_ordered', type: 'decimal', precision: 19, scale: 4)]
    private string $quantityOrdered;

    #[ORM\Column(name: 'quantity_reserved', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $quantityReserved = '0.0000';

    #[ORM\Column(name: 'quantity_backordered', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $quantityBackordered = '0.0000';

    #[ORM\Column(name: 'quantity_delivered', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $quantityDelivered = '0.0000';

    #[ORM\Column(name: 'unit_price_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $unitPriceAmount;

    #[ORM\Column(name: 'unit_price_currency', length: 3)]
    private string $unitPriceCurrency;

    #[ORM\Column(name: 'tax_rate', type: 'decimal', precision: 8, scale: 4)]
    private string $taxRate;

    #[ORM\Column(name: 'discount_amount', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $discountAmount = '0.0000';

    #[ORM\Column(name: 'line_subtotal_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $lineSubtotalAmount;

    #[ORM\Column(name: 'line_tax_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $lineTaxAmount;

    #[ORM\Column(name: 'line_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $lineTotalAmount;

    #[ORM\Column(name: 'line_status', length: 32, enumType: OrderLineStatus::class)]
    private OrderLineStatus $lineStatus;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(
        EntityId $id,
        Order $order,
        ProductVariant $variant,
        string $productId,
        string $productName,
        string $variantName,
        string $sku,
        Quantity $quantityOrdered,
        Money $unitPrice,
        string $taxRate,
        Money $discountAmount,
        Money $lineSubtotal,
        Money $lineTax,
        Money $lineTotal,
        int $sortOrder = 0,
    ) {
        $this->id = $id->toString();
        $this->order = $order;
        $this->variant = $variant;
        $this->productId = $productId;
        $this->productName = $productName;
        $this->variantName = $variantName;
        $this->sku = $sku;
        $this->quantityOrdered = $quantityOrdered->amount();
        $this->unitPriceAmount = $unitPrice->amount();
        $this->unitPriceCurrency = $unitPrice->currency();
        $this->taxRate = $taxRate;
        $this->discountAmount = $discountAmount->amount();
        $this->lineSubtotalAmount = $lineSubtotal->amount();
        $this->lineTaxAmount = $lineTax->amount();
        $this->lineTotalAmount = $lineTotal->amount();
        $this->lineStatus = OrderLineStatus::Unallocated;
        $this->sortOrder = $sortOrder;
        $order->addItem($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getVariantName(): string
    {
        return $this->variantName;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQuantityOrdered(): Quantity
    {
        return Quantity::of($this->quantityOrdered);
    }

    public function getQuantityReserved(): Quantity
    {
        return Quantity::of($this->quantityReserved);
    }

    public function getQuantityBackordered(): Quantity
    {
        return Quantity::of($this->quantityBackordered);
    }

    public function getQuantityDelivered(): Quantity
    {
        return Quantity::of($this->quantityDelivered);
    }

    public function getUnitPrice(): Money
    {
        return Money::of($this->unitPriceAmount, $this->unitPriceCurrency);
    }

    public function getTaxRate(): string
    {
        return $this->taxRate;
    }

    public function getDiscountAmount(): Money
    {
        return Money::of($this->discountAmount, $this->unitPriceCurrency);
    }

    public function getLineSubtotal(): Money
    {
        return Money::of($this->lineSubtotalAmount, $this->unitPriceCurrency);
    }

    public function getLineTax(): Money
    {
        return Money::of($this->lineTaxAmount, $this->unitPriceCurrency);
    }

    public function getLineTotal(): Money
    {
        return Money::of($this->lineTotalAmount, $this->unitPriceCurrency);
    }

    public function getLineStatus(): OrderLineStatus
    {
        return $this->lineStatus;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function applyAllocation(Quantity $reserved, Quantity $backordered, OrderLineStatus $lineStatus): void
    {
        $this->quantityReserved = $reserved->amount();
        $this->quantityBackordered = $backordered->amount();
        $this->lineStatus = $lineStatus;
    }

    public function setLineStatus(OrderLineStatus $lineStatus): void
    {
        $this->lineStatus = $lineStatus;
    }

    public function clearAllocation(): void
    {
        $this->quantityReserved = '0.0000';
        $this->quantityBackordered = '0.0000';
        $this->lineStatus = OrderLineStatus::Unallocated;
    }

    public function fulfillBackorder(Quantity $quantity): void
    {
        $backordered = $this->getQuantityBackordered();

        if ($quantity->compare($backordered) > 0) {
            throw new \DomainException('Cannot fulfill more than the backordered quantity.');
        }

        $reserved = $this->getQuantityReserved()->add($quantity);
        $remainingBackorder = $backordered->subtract($quantity);

        if ($remainingBackorder->isZero() && $reserved->equals($this->getQuantityOrdered())) {
            $lineStatus = OrderLineStatus::Ready;
        } elseif ($remainingBackorder->isZero()) {
            $lineStatus = OrderLineStatus::Reserved;
        } else {
            $lineStatus = OrderLineStatus::Backordered;
        }

        $this->applyAllocation($reserved, $remainingBackorder, $lineStatus);
    }

    public function recordDelivery(Quantity $quantity): void
    {
        $delivered = $this->getQuantityDelivered()->add($quantity);

        if ($delivered->compare($this->getQuantityOrdered()) > 0) {
            throw new \DomainException('Cannot deliver more than the ordered quantity.');
        }

        $this->quantityDelivered = $delivered->amount();

        if ($delivered->equals($this->getQuantityOrdered())) {
            $this->lineStatus = OrderLineStatus::Delivered;
        }
    }

    public function getRemainingDeliverableQuantity(): Quantity
    {
        return $this->getQuantityOrdered()->subtract($this->getQuantityDelivered());
    }
}
