<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a recipe (bill of materials): how much of a raw material one unit of a finished
 * variant consumes, e.g. 0.45 kg of stoneware clay and 0.03 kg of white glaze per bowl.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_components')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCT_COMPONENT', columns: ['variant_id', 'component_variant_id'])]
class ProductComponent implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'component_variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $component;

    #[ORM\Column(name: 'quantity_per_unit', type: 'decimal', precision: 19, scale: 4)]
    private string $quantityPerUnit;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        ProductVariant $variant,
        ProductVariant $component,
        Quantity $quantityPerUnit,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->variant = $variant;
        $this->component = $component;
        $this->quantityPerUnit = $quantityPerUnit->amount();
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

    public function getComponent(): ProductVariant
    {
        return $this->component;
    }

    public function getQuantityPerUnit(): Quantity
    {
        return Quantity::of($this->quantityPerUnit);
    }

    public function changeQuantity(Quantity $quantityPerUnit): void
    {
        $this->quantityPerUnit = $quantityPerUnit->amount();
    }
}
