<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_products')]
#[ORM\UniqueConstraint(name: 'UNIQ_SUPPLIER_VARIANT', columns: ['supplier_id', 'variant_id'])]
class SupplierProduct
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Supplier::class, inversedBy: 'products')]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(name: 'supplier_sku', length: 64, nullable: true)]
    private ?string $supplierSku;

    #[ORM\Column(name: 'purchase_price_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $purchasePriceAmount;

    #[ORM\Column(name: 'purchase_price_currency', length: 3)]
    private string $purchasePriceCurrency;

    #[ORM\Column(name: 'lead_time_days', nullable: true)]
    private ?int $leadTimeDays;

    #[ORM\Column(name: 'minimum_order_qty', type: 'decimal', precision: 19, scale: 4, nullable: true)]
    private ?string $minimumOrderQty;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Supplier $supplier,
        ProductVariant $variant,
        Money $purchasePrice,
        ?string $supplierSku = null,
        ?int $leadTimeDays = null,
        ?string $minimumOrderQty = null,
    ) {
        $this->id = $id->toString();
        $this->supplier = $supplier;
        $this->variant = $variant;
        $this->supplierSku = $supplierSku;
        $this->purchasePriceAmount = $purchasePrice->amount();
        $this->purchasePriceCurrency = $purchasePrice->currency();
        $this->leadTimeDays = $leadTimeDays;
        $this->minimumOrderQty = $minimumOrderQty;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getSupplierSku(): ?string
    {
        return $this->supplierSku;
    }

    public function getPurchasePrice(): Money
    {
        return Money::of($this->purchasePriceAmount, $this->purchasePriceCurrency);
    }

    public function getLeadTimeDays(): ?int
    {
        return $this->leadTimeDays;
    }

    public function getMinimumOrderQty(): ?string
    {
        return $this->minimumOrderQty;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function update(Money $purchasePrice, ?string $supplierSku, ?int $leadTimeDays, ?string $minimumOrderQty, bool $isActive): void
    {
        $this->purchasePriceAmount = $purchasePrice->amount();
        $this->purchasePriceCurrency = $purchasePrice->currency();
        $this->supplierSku = $supplierSku;
        $this->leadTimeDays = $leadTimeDays;
        $this->minimumOrderQty = $minimumOrderQty;
        $this->isActive = $isActive;
    }
}
