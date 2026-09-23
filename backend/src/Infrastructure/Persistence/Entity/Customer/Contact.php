<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Customer;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_contacts')]
class Contact
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $role;

    #[ORM\Column(name: 'is_primary', options: ['default' => false])]
    private bool $isPrimary = false;

    public function __construct(
        EntityId $id,
        Customer $customer,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?string $role = null,
        bool $isPrimary = false,
    ) {
        $this->id = $id->toString();
        $this->customer = $customer;
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->role = $role;
        $this->isPrimary = $isPrimary;
        $customer->addContact($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function update(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $role,
        bool $isPrimary,
    ): void {
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->role = $role;
        $this->isPrimary = $isPrimary;
    }
}
