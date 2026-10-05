<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Raw material drawn from stock for one product of a production order, with the cost it left
 * stock at. These rows are what the finished pieces are costed from.
 */
#[ORM\Entity]
#[ORM\Table(name: 'production_materials')]
class ProductionMaterial
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ProductionOrder::class)]
    #[ORM\JoinColumn(name: 'production_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductionOrder $productionOrder;

    #[ORM\ManyToOne(targetEntity: ProductionItem::class)]
    #[ORM\JoinColumn(name: 'production_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductionItem $productionItem;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 19, scale: 4)]
    private string $unitCost;

    public function __construct(
        EntityId $id,
        ProductionOrder $productionOrder,
        ProductionItem $productionItem,
        ProductVariant $variant,
        Quantity $quantity,
        string $unitCost,
    ) {
        $this->id = $id->toString();
        $this->productionOrder = $productionOrder;
        $this->productionItem = $productionItem;
        $this->variant = $variant;
        $this->quantity = $quantity->amount();
        $this->unitCost = $unitCost;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProductionOrder(): ProductionOrder
    {
        return $this->productionOrder;
    }

    public function getProductionItem(): ProductionItem
    {
        return $this->productionItem;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function getUnitCost(): string
    {
        return bcadd($this->unitCost, '0', 4);
    }

    public function getCost(): string
    {
        return bcadd(bcmul($this->getQuantity()->amount(), $this->getUnitCost(), 6), '0.00005', 4);
    }
}
