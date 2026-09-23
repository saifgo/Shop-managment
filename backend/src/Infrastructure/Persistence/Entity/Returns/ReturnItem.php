<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Returns;

use App\Domain\Returns\ReturnItemCondition;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Sales\DeliveryLine;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'return_items')]
class ReturnItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ReturnRequest::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'return_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ReturnRequest $returnRequest;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private OrderItem $orderItem;

    #[ORM\ManyToOne(targetEntity: DeliveryLine::class)]
    #[ORM\JoinColumn(name: 'delivery_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DeliveryLine $deliveryLine;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason;

    #[ORM\Column(name: 'item_condition', length: 32, enumType: ReturnItemCondition::class, nullable: true)]
    private ?ReturnItemCondition $condition = null;

    #[ORM\Column(name: 'inspection_notes', type: 'text', nullable: true)]
    private ?string $inspectionNotes;

    public function __construct(
        EntityId $id,
        ReturnRequest $returnRequest,
        OrderItem $orderItem,
        Quantity $quantity,
        ?DeliveryLine $deliveryLine = null,
        ?string $reason = null,
    ) {
        $this->id = $id->toString();
        $this->returnRequest = $returnRequest;
        $this->orderItem = $orderItem;
        $this->deliveryLine = $deliveryLine;
        $this->quantity = $quantity->amount();
        $this->reason = $reason;
        $this->inspectionNotes = null;
        $returnRequest->addItem($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getReturnRequest(): ReturnRequest
    {
        return $this->returnRequest;
    }

    public function getOrderItem(): OrderItem
    {
        return $this->orderItem;
    }

    public function getDeliveryLine(): ?DeliveryLine
    {
        return $this->deliveryLine;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCondition(): ?ReturnItemCondition
    {
        return $this->condition;
    }

    public function getInspectionNotes(): ?string
    {
        return $this->inspectionNotes;
    }

    public function inspect(ReturnItemCondition $condition, ?string $notes = null): void
    {
        $this->condition = $condition;
        $this->inspectionNotes = $notes;
    }
}
