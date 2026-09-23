<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Returns;

use App\Domain\Returns\ReturnResolution;
use App\Domain\Returns\ReturnStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Sales\Delivery;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'return_requests')]
#[ORM\UniqueConstraint(name: 'UNIQ_RETURN_REFERENCE', columns: ['company_id', 'reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_RETURN_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
class ReturnRequest implements CompanyScoped
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

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\Column(length: 32, enumType: ReturnStatus::class)]
    private ReturnStatus $status;

    #[ORM\Column(length: 32, enumType: ReturnResolution::class, nullable: true)]
    private ?ReturnResolution $resolution = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'credit_note_id', type: 'string', length: 26, nullable: true)]
    private ?string $creditNoteId = null;

    #[ORM\ManyToOne(targetEntity: Delivery::class)]
    #[ORM\JoinColumn(name: 'replacement_delivery_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Delivery $replacementDelivery = null;

    #[ORM\Column(name: 'requested_by', type: 'string', length: 26, nullable: true)]
    private ?string $requestedBy;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var Collection<int, ReturnItem> */
    #[ORM\OneToMany(mappedBy: 'returnRequest', targetEntity: ReturnItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, ReturnEvent> */
    #[ORM\OneToMany(mappedBy: 'returnRequest', targetEntity: ReturnEvent::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $events;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        Customer $customer,
        Order $order,
        string $reference,
        ?string $reason = null,
        ?string $notes = null,
        ?EntityId $requestedBy = null,
        ?string $idempotencyKey = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->customer = $customer;
        $this->order = $order;
        $this->reference = $reference;
        $this->status = ReturnStatus::Requested;
        $this->reason = $reason;
        $this->notes = $notes;
        $this->requestedBy = $requestedBy?->toString();
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->items = new ArrayCollection();
        $this->events = new ArrayCollection();
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

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getStatus(): ReturnStatus
    {
        return $this->status;
    }

    public function getResolution(): ?ReturnResolution
    {
        return $this->resolution;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreditNoteId(): ?string
    {
        return $this->creditNoteId;
    }

    public function getReplacementDelivery(): ?Delivery
    {
        return $this->replacementDelivery;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /** @return Collection<int, ReturnItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, ReturnEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addItem(ReturnItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }

    public function addEvent(ReturnEvent $event): void
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }
    }

    public function transitionTo(ReturnStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function resolve(ReturnResolution $resolution): void
    {
        $this->resolution = $resolution;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->resolvedAt;
    }

    public function setCreditNoteId(string $creditNoteId): void
    {
        $this->creditNoteId = $creditNoteId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setReplacementDelivery(Delivery $delivery): void
    {
        $this->replacementDelivery = $delivery;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
