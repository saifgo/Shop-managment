<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'price_lists')]
class PriceList implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'is_default', options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PriceListItem> */
    #[ORM\OneToMany(mappedBy: 'priceList', targetEntity: PriceListItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $code,
        string $name,
        string $currency,
        bool $isDefault = false,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->code = $code;
        $this->name = $name;
        $this->currency = strtoupper($currency);
        $this->isDefault = $isDefault;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    /** @return Collection<int, PriceListItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PriceListItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'price_list_items')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRICE_LIST_VARIANT', columns: ['price_list_id', 'variant_id'])]
class PriceListItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: PriceList::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'price_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PriceList $priceList;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    public function __construct(
        EntityId $id,
        PriceList $priceList,
        ProductVariant $variant,
        Money $price,
    ) {
        $this->id = $id->toString();
        $this->priceList = $priceList;
        $this->variant = $variant;
        $this->amount = $price->amount();
        $this->currency = $price->currency();
        $priceList->addItem($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPriceList(): PriceList
    {
        return $this->priceList;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getPrice(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function updatePrice(Money $price): void
    {
        $this->amount = $price->amount();
        $this->currency = $price->currency();
    }
}
