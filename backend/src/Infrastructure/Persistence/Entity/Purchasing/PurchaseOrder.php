<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_orders')]
#[ORM\UniqueConstraint(name: 'UNIQ_PO_REFERENCE', columns: ['company_id', 'reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PO_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
class PurchaseOrder implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Supplier $supplier;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: 32, enumType: PurchaseOrderStatus::class)]
    private PurchaseOrderStatus $status;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'expected_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expectedAt;

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

    /** @var Collection<int, PurchaseOrderItem> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        Supplier $supplier,
        string $reference,
        string $currency,
        ?\DateTimeImmutable $expectedAt = null,
        ?string $notes = null,
        ?EntityId $createdBy = null,
        ?string $idempotencyKey = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->supplier = $supplier;
        $this->reference = $reference;
        $this->status = PurchaseOrderStatus::Draft;
        $this->currency = $currency;
        $this->expectedAt = $expectedAt;
        $this->notes = $notes;
        $this->createdBy = $createdBy?->toString();
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->items = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getStatus(): PurchaseOrderStatus
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getExpectedAt(): ?\DateTimeImmutable
    {
        return $this->expectedAt;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, PurchaseOrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseOrderItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }

    public function setStatus(PurchaseOrderStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getGrandTotal(): Money
    {
        $total = '0.0000';

        foreach ($this->items as $item) {
            $total = bcadd($total, $item->getLineTotal()->amount(), 4);
        }

        return Money::of($total, $this->currency);
    }

    public function recalculateReceiptStatus(): void
    {
        $allReceived = true;
        $anyReceived = false;

        foreach ($this->items as $item) {
            if (!$item->getQuantityReceived()->isZero()) {
                $anyReceived = true;
            }

            if ($item->getQuantityReceived()->compare($item->getQuantityOrdered()) < 0) {
                $allReceived = false;
            }
        }

        if ($allReceived && $anyReceived) {
            $this->status = PurchaseOrderStatus::Received;
        } elseif ($anyReceived) {
            $this->status = PurchaseOrderStatus::PartiallyReceived;
        }

        $this->updatedAt = new \DateTimeImmutable();
    }
}
