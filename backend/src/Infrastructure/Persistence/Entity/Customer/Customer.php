<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Customer;

use App\Domain\Customer\CustomerType;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\CustomerPriceOverride;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
class Customer implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 16, enumType: CustomerType::class)]
    private CustomerType $type;

    #[ORM\Column(name: 'display_name', length: 200)]
    private string $displayName;

    #[ORM\Column(name: 'legal_name', length: 200, nullable: true)]
    private ?string $legalName;

    #[ORM\Column(name: 'tax_id', length: 64, nullable: true)]
    private ?string $taxId;

    #[ORM\Column(name: 'vat_number', length: 64, nullable: true)]
    private ?string $vatNumber;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CustomerIdentity> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: CustomerIdentity::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $identities;

    /** @var Collection<int, Address> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: Address::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $addresses;

    /** @var Collection<int, Contact> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: Contact::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $contacts;

    /** @var Collection<int, CustomerPriceOverride> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: CustomerPriceOverride::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $priceOverrides;

    #[ORM\OneToOne(mappedBy: 'customer', targetEntity: PortalUser::class, cascade: ['persist'], orphanRemoval: true)]
    private ?PortalUser $portalUser = null;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        CustomerType $type,
        string $displayName,
        ?string $legalName = null,
        ?string $taxId = null,
        ?string $vatNumber = null,
        ?string $notes = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->type = $type;
        $this->displayName = $displayName;
        $this->legalName = $legalName;
        $this->taxId = $taxId;
        $this->vatNumber = $vatNumber;
        $this->notes = $notes;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->identities = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->priceOverrides = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getType(): CustomerType
    {
        return $this->type;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** @return Collection<int, CustomerIdentity> */
    public function getIdentities(): Collection
    {
        return $this->identities;
    }

    /** @return Collection<int, Address> */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    /** @return Collection<int, Contact> */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    /** @return Collection<int, CustomerPriceOverride> */
    public function getPriceOverrides(): Collection
    {
        return $this->priceOverrides;
    }

    public function getPortalUser(): ?PortalUser
    {
        return $this->portalUser;
    }

    public function addIdentity(CustomerIdentity $identity): void
    {
        if (!$this->identities->contains($identity)) {
            $this->identities->add($identity);
        }
    }

    public function addAddress(Address $address): void
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
        }
    }

    public function addContact(Contact $contact): void
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
        }
    }

    public function addPriceOverride(CustomerPriceOverride $override): void
    {
        if (!$this->priceOverrides->contains($override)) {
            $this->priceOverrides->add($override);
        }
    }

    public function setPortalUser(PortalUser $portalUser): void
    {
        $this->portalUser = $portalUser;
    }

    public function update(
        CustomerType $type,
        string $displayName,
        ?string $legalName,
        ?string $taxId,
        ?string $vatNumber,
        ?string $notes,
        bool $isActive,
    ): void {
        $this->type = $type;
        $this->displayName = $displayName;
        $this->legalName = $legalName;
        $this->taxId = $taxId;
        $this->vatNumber = $vatNumber;
        $this->notes = $notes;
        $this->isActive = $isActive;
        $this->touch();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
