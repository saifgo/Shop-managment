<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Customer;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customer_identities')]
class CustomerIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'identities')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(length: 128)]
    private string $value;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Customer $customer,
        string $type,
        string $value,
    ) {
        $this->id = $id->toString();
        $this->customer = $customer;
        $this->type = $type;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
        $customer->addIdentity($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
