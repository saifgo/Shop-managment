<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Inventory;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_adjustments')]
class StockAdjustment implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: StockLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StockLocation $location;

    #[ORM\ManyToOne(targetEntity: StockMovement::class)]
    #[ORM\JoinColumn(name: 'movement_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StockMovement $movement;

    #[ORM\Column(name: 'quantity_delta', type: 'decimal', precision: 19, scale: 4)]
    private string $quantityDelta;

    #[ORM\Column(length: 255)]
    private string $reason;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
        StockMovement $movement,
        Quantity $quantityDelta,
        string $reason,
        ?EntityId $createdBy = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->variant = $variant;
        $this->location = $location;
        $this->movement = $movement;
        $this->quantityDelta = $quantityDelta->amount();
        $this->reason = $reason;
        $this->createdBy = $createdBy?->toString();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getLocation(): StockLocation
    {
        return $this->location;
    }

    public function getMovement(): StockMovement
    {
        return $this->movement;
    }

    public function getQuantityDelta(): Quantity
    {
        return Quantity::of($this->quantityDelta);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
