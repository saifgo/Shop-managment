<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Customer;

use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Identity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'portal_users')]
class PortalUser
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\OneToOne(targetEntity: Customer::class, inversedBy: 'portalUser')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Customer $customer,
        User $user,
    ) {
        $this->id = $id->toString();
        $this->customer = $customer;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $customer->setPortalUser($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
