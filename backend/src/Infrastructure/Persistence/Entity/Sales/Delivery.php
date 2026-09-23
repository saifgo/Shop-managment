<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Sales\DeliveryStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'deliveries')]
#[ORM\UniqueConstraint(name: 'UNIQ_DELIVERY_REFERENCE', columns: ['company_id', 'reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_DELIVERY_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
class Delivery implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: 32, enumType: DeliveryStatus::class)]
    private DeliveryStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'tracking_reference', length: 128, nullable: true)]
    private ?string $trackingReference;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'dispatched_at', nullable: true)]
    private ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(name: 'delivered_at', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    /** @var Collection<int, DeliveryLine> */
    #[ORM\OneToMany(mappedBy: 'delivery', targetEntity: DeliveryLine::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        Order $order,
        string $reference,
        ?string $notes = null,
        ?EntityId $createdBy = null,
        ?string $idempotencyKey = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->order = $order;
        $this->reference = $reference;
        $this->status = DeliveryStatus::ReadyToDeliver;
        $this->notes = $notes;
        $this->trackingReference = null;
        $this->createdBy = $createdBy?->toString();
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lines = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getStatus(): DeliveryStatus
    {
        return $this->status;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getTrackingReference(): ?string
    {
        return $this->trackingReference;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDispatchedAt(): ?\DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    /** @return Collection<int, DeliveryLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(DeliveryLine $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    public function transitionTo(DeliveryStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        if ($status === DeliveryStatus::Dispatched && $this->dispatchedAt === null) {
            $this->dispatchedAt = $this->updatedAt;
        }

        if ($status === DeliveryStatus::Delivered && $this->deliveredAt === null) {
            $this->deliveredAt = $this->updatedAt;
        }
    }

    public function setTrackingReference(?string $trackingReference): void
    {
        $this->trackingReference = $trackingReference;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
