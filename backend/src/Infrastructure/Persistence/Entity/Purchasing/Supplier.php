<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'suppliers')]
#[ORM\UniqueConstraint(name: 'UNIQ_SUPPLIER_CODE', columns: ['company_id', 'code'])]
class Supplier implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(name: 'contact_email', length: 255, nullable: true)]
    private ?string $contactEmail;

    #[ORM\Column(name: 'contact_phone', length: 64, nullable: true)]
    private ?string $contactPhone;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address;

    #[ORM\Column(name: 'tax_id', length: 64, nullable: true)]
    private ?string $taxId;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SupplierProduct> */
    #[ORM\OneToMany(mappedBy: 'supplier', targetEntity: SupplierProduct::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $products;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $code,
        string $name,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        ?string $address = null,
        ?string $taxId = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->code = $code;
        $this->name = $name;
        $this->contactEmail = $contactEmail;
        $this->contactPhone = $contactPhone;
        $this->address = $address;
        $this->taxId = $taxId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->products = new ArrayCollection();
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

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, SupplierProduct> */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function update(
        string $name,
        ?string $contactEmail,
        ?string $contactPhone,
        ?string $address,
        ?string $taxId,
        bool $isActive,
    ): void {
        $this->name = $name;
        $this->contactEmail = $contactEmail;
        $this->contactPhone = $contactPhone;
        $this->address = $address;
        $this->taxId = $taxId;
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
