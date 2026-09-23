<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchase_receipts')]
#[ORM\UniqueConstraint(name: 'UNIQ_RECEIPT_REFERENCE', columns: ['company_id', 'reference'])]
#[ORM\UniqueConstraint(name: 'UNIQ_RECEIPT_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
class PurchaseReceipt implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(name: 'received_at')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PurchaseReceiptItem> */
    #[ORM\OneToMany(mappedBy: 'purchaseReceipt', targetEntity: PurchaseReceiptItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        PurchaseOrder $purchaseOrder,
        string $reference,
        ?string $notes = null,
        ?EntityId $createdBy = null,
        ?string $idempotencyKey = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->purchaseOrder = $purchaseOrder;
        $this->reference = $reference;
        $this->receivedAt = new \DateTimeImmutable();
        $this->notes = $notes;
        $this->createdBy = $createdBy?->toString();
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /** @return Collection<int, PurchaseReceiptItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseReceiptItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }
}
