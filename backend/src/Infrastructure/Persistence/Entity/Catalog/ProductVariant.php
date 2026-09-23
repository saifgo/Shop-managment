<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_variants')]
#[ORM\UniqueConstraint(name: 'UNIQ_VARIANT_SKU', columns: ['company_id', 'sku'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(name: 'base_price_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $basePriceAmount;

    #[ORM\Column(name: 'base_price_currency', length: 3)]
    private string $basePriceCurrency;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $attributes = [];

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        Product $product,
        string $sku,
        string $name,
        Money $basePrice,
        array $attributes = [],
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->product = $product;
        $this->sku = $sku;
        $this->name = $name;
        $this->basePriceAmount = $basePrice->amount();
        $this->basePriceCurrency = $basePrice->currency();
        $this->attributes = $attributes;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $product->addVariant($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBasePrice(): Money
    {
        return Money::of($this->basePriceAmount, $this->basePriceCurrency);
    }

    /** @return array<string, string> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function update(string $sku, string $name, Money $basePrice, array $attributes, bool $isActive): void
    {
        $this->sku = $sku;
        $this->name = $name;
        $this->basePriceAmount = $basePrice->amount();
        $this->basePriceCurrency = $basePrice->currency();
        $this->attributes = $attributes;
        $this->isActive = $isActive;
        $this->touch();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
