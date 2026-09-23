<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Sales\OrderStatus;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_status_history')]
class OrderStatusHistory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'statusHistory')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(name: 'from_status', length: 32, enumType: OrderStatus::class)]
    private OrderStatus $fromStatus;

    #[ORM\Column(name: 'to_status', length: 32, enumType: OrderStatus::class)]
    private OrderStatus $toStatus;

    #[ORM\Column(name: 'changed_by', type: 'string', length: 26, nullable: true)]
    private ?string $changedBy;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Order $order,
        OrderStatus $fromStatus,
        OrderStatus $toStatus,
        ?EntityId $changedBy = null,
        ?string $reason = null,
    ) {
        $this->id = $id->toString();
        $this->order = $order;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->changedBy = $changedBy?->toString();
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getFromStatus(): OrderStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): OrderStatus
    {
        return $this->toStatus;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
