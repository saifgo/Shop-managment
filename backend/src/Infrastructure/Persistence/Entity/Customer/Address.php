<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Customer;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_addresses')]
class Address
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(name: 'line1', length: 200)]
    private string $line1;

    #[ORM\Column(name: 'line2', length: 200, nullable: true)]
    private ?string $line2;

    #[ORM\Column(length: 100)]
    private string $city;

    #[ORM\Column(name: 'postal_code', length: 32)]
    private string $postalCode;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(name: 'is_default', options: ['default' => false])]
    private bool $isDefault = false;

    public function __construct(
        EntityId $id,
        Customer $customer,
        string $type,
        string $line1,
        string $city,
        string $postalCode,
        string $country,
        ?string $line2 = null,
        bool $isDefault = false,
    ) {
        $this->id = $id->toString();
        $this->customer = $customer;
        $this->type = $type;
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = strtoupper($country);
        $this->isDefault = $isDefault;
        $customer->addAddress($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLine1(): string
    {
        return $this->line1;
    }

    public function getLine2(): ?string
    {
        return $this->line2;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function update(
        string $type,
        string $line1,
        ?string $line2,
        string $city,
        string $postalCode,
        string $country,
        bool $isDefault,
    ): void {
        $this->type = $type;
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = strtoupper($country);
        $this->isDefault = $isDefault;
    }
}
