<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Inventory;

use App\Domain\Inventory\StockMovementType;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_movements')]
#[ORM\Index(name: 'IDX_STOCK_MOVEMENTS_VARIANT', columns: ['variant_id'])]
#[ORM\Index(name: 'IDX_STOCK_MOVEMENTS_SOURCE', columns: ['source_type', 'source_id'])]
class StockMovement implements CompanyScoped
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

    #[ORM\Column(name: 'movement_type', length: 32, enumType: StockMovementType::class)]
    private StockMovementType $movementType;

    #[ORM\Column(name: 'quantity_delta', type: 'decimal', precision: 19, scale: 4)]
    private string $quantityDelta;

    #[ORM\Column(name: 'reserved_delta', type: 'decimal', precision: 19, scale: 4)]
    private string $reservedDelta;

    #[ORM\Column(name: 'source_type', length: 64)]
    private string $sourceType;

    #[ORM\Column(name: 'source_id', type: 'string', length: 26)]
    private string $sourceId;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $reference;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
        StockMovementType $movementType,
        string $quantityDelta,
        string $reservedDelta,
        string $sourceType,
        EntityId $sourceId,
        ?string $reference = null,
        ?string $notes = null,
        ?EntityId $createdBy = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->variant = $variant;
        $this->location = $location;
        $this->movementType = $movementType;
        $this->quantityDelta = self::normalizeDelta($quantityDelta);
        $this->reservedDelta = self::normalizeDelta($reservedDelta);
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId->toString();
        $this->reference = $reference;
        $this->notes = $notes;
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

    public function getMovementType(): StockMovementType
    {
        return $this->movementType;
    }

    public function getQuantityDelta(): string
    {
        return $this->quantityDelta;
    }

    public function getReservedDelta(): string
    {
        return $this->reservedDelta;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function normalizeDelta(string $amount): string
    {
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException(sprintf('Invalid movement delta: %s', $amount));
        }

        if (!str_contains($amount, '.')) {
            return $amount.'.0000';
        }

        [$whole, $fraction] = explode('.', $amount, 2);
        $fraction = str_pad(substr($fraction, 0, 4), 4, '0');

        return $whole.'.'.$fraction;
    }
}
