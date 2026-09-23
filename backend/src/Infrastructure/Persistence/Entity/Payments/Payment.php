<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Payments;

use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'UNIQ_PAYMENT_REFERENCE', columns: ['company_id', 'reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PAYMENT_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
class Payment implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(name: 'allocated_amount', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $allocatedAmount = '0.0000';

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 32, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(name: 'payment_date', type: 'date_immutable')]
    private \DateTimeImmutable $paymentDate;

    #[ORM\Column(length: 32, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PaymentAllocation> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: PaymentAllocation::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $allocations;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $reference,
        Customer $customer,
        Money $amount,
        PaymentMethod $method,
        \DateTimeImmutable $paymentDate,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?EntityId $createdBy = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->reference = $reference;
        $this->customer = $customer;
        $this->amount = $amount->amount();
        $this->currency = $amount->currency();
        $this->method = $method;
        $this->paymentDate = $paymentDate;
        $this->status = PaymentStatus::Recorded;
        $this->notes = $notes;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdBy = $createdBy?->toString();
        $this->createdAt = new \DateTimeImmutable();
        $this->allocations = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getAmount(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function getAllocatedAmount(): Money
    {
        return Money::of($this->allocatedAmount, $this->currency);
    }

    public function getUnallocatedAmount(): Money
    {
        return $this->getAmount()->subtract($this->getAllocatedAmount());
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function getPaymentDate(): \DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, PaymentAllocation> */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function addAllocation(PaymentAllocation $allocation): void
    {
        if (!$this->allocations->contains($allocation)) {
            $this->allocations->add($allocation);
        }
    }

    public function applyAllocation(Money $amount): void
    {
        $newAllocated = $this->getAllocatedAmount()->add($amount);

        if (bccomp($newAllocated->amount(), $this->amount, 4) > 0) {
            throw new \DomainException('Allocation exceeds payment amount.');
        }

        $this->allocatedAmount = $newAllocated->amount();

        if ($newAllocated->equals($this->getAmount())) {
            $this->status = PaymentStatus::FullyAllocated;
        } elseif (!$newAllocated->isZero()) {
            $this->status = PaymentStatus::PartiallyAllocated;
        }
    }
}
