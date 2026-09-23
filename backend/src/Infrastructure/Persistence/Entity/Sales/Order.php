<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Sales\OrderStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORDER_REFERENCE', columns: ['company_id', 'reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_ORDER_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
class Order implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: 32, enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'subtotal_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $subtotalAmount;

    #[ORM\Column(name: 'tax_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $taxTotalAmount;

    #[ORM\Column(name: 'discount_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $discountTotalAmount;

    #[ORM\Column(name: 'grand_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $grandTotalAmount;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'submitted_at', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'confirmed_at', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, OrderStatusHistory> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderStatusHistory::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $statusHistory;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        Customer $customer,
        string $reference,
        OrderStatus $status,
        string $currency,
        Money $subtotal,
        Money $taxTotal,
        Money $discountTotal,
        Money $grandTotal,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?EntityId $createdBy = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->customer = $customer;
        $this->reference = $reference;
        $this->status = $status;
        $this->currency = strtoupper($currency);
        $this->subtotalAmount = $subtotal->amount();
        $this->taxTotalAmount = $taxTotal->amount();
        $this->discountTotalAmount = $discountTotal->amount();
        $this->grandTotalAmount = $grandTotal->amount();
        $this->notes = $notes;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdBy = $createdBy?->toString();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->items = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();

        if ($status === OrderStatus::Submitted) {
            $this->submittedAt = $this->createdAt;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSubtotal(): Money
    {
        return Money::of($this->subtotalAmount, $this->currency);
    }

    public function getTaxTotal(): Money
    {
        return Money::of($this->taxTotalAmount, $this->currency);
    }

    public function getDiscountTotal(): Money
    {
        return Money::of($this->discountTotalAmount, $this->currency);
    }

    public function getGrandTotal(): Money
    {
        return Money::of($this->grandTotalAmount, $this->currency);
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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, OrderStatusHistory> */
    public function getStatusHistory(): Collection
    {
        return $this->statusHistory;
    }

    public function addItem(OrderItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }

    public function transitionTo(OrderStatus $newStatus, ?EntityId $changedBy = null, ?string $reason = null): void
    {
        $history = new OrderStatusHistory(
            EntityId::generate(),
            $this,
            $this->status,
            $newStatus,
            $changedBy,
            $reason,
        );
        $this->statusHistory->add($history);
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        if ($newStatus === OrderStatus::Confirmed && $this->confirmedAt === null) {
            $this->confirmedAt = $this->updatedAt;
        }
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
