<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_price_overrides')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOMER_VARIANT_OVERRIDE', columns: ['customer_id', 'variant_id'])]
class CustomerPriceOverride
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'priceOverrides')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'valid_from', nullable: true)]
    private ?\DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', nullable: true)]
    private ?\DateTimeImmutable $validUntil;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Customer $customer,
        ProductVariant $variant,
        Money $price,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null,
    ) {
        $this->id = $id->toString();
        $this->customer = $customer;
        $this->variant = $variant;
        $this->amount = $price->amount();
        $this->currency = $price->currency();
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->createdAt = new \DateTimeImmutable();
        $customer->addPriceOverride($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getPrice(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isActiveAt(\DateTimeImmutable $at): bool
    {
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $at > $this->validUntil) {
            return false;
        }

        return true;
    }

    public function update(Money $price, ?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validUntil): void
    {
        $this->amount = $price->amount();
        $this->currency = $price->currency();
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
    }
}
