<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'delivery_lines')]
class DeliveryLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Delivery::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Delivery $delivery;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private OrderItem $orderItem;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    public function __construct(
        EntityId $id,
        Delivery $delivery,
        OrderItem $orderItem,
        Quantity $quantity,
    ) {
        $this->id = $id->toString();
        $this->delivery = $delivery;
        $this->orderItem = $orderItem;
        $this->quantity = $quantity->amount();
        $delivery->addLine($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDelivery(): Delivery
    {
        return $this->delivery;
    }

    public function getOrderItem(): OrderItem
    {
        return $this->orderItem;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }
}
