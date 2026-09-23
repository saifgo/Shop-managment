<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_items')]
class ProductionItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ProductionOrder::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'production_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductionOrder $productionOrder;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(name: 'planned_quantity', type: 'decimal', precision: 19, scale: 4)]
    private string $plannedQuantity;

    #[ORM\Column(name: 'accepted_output_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $acceptedOutputQuantity = '0.0000';

    public function __construct(
        EntityId $id,
        ProductionOrder $productionOrder,
        ProductVariant $variant,
        Quantity $plannedQuantity,
    ) {
        $this->id = $id->toString();
        $this->productionOrder = $productionOrder;
        $this->variant = $variant;
        $this->plannedQuantity = $plannedQuantity->amount();
        $productionOrder->addItem($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProductionOrder(): ProductionOrder
    {
        return $this->productionOrder;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getPlannedQuantity(): Quantity
    {
        return Quantity::of($this->plannedQuantity);
    }

    public function getAcceptedOutputQuantity(): Quantity
    {
        return Quantity::of($this->acceptedOutputQuantity);
    }

    public function setAcceptedOutputQuantity(Quantity $quantity): void
    {
        $this->acceptedOutputQuantity = $quantity->amount();
    }
}
