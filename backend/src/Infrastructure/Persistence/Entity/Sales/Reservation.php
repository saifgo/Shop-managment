<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Inventory\ReservationStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reservations')]
#[ORM\Index(name: 'IDX_RESERVATIONS_ORDER_ITEM', columns: ['order_item_id'])]
class Reservation implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private OrderItem $orderItem;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: StockLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StockLocation $location;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    #[ORM\Column(length: 16, enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        OrderItem $orderItem,
        ProductVariant $variant,
        StockLocation $location,
        Quantity $quantity,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->orderItem = $orderItem;
        $this->variant = $variant;
        $this->location = $location;
        $this->quantity = $quantity->amount();
        $this->status = ReservationStatus::Active;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getOrderItem(): OrderItem
    {
        return $this->orderItem;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getLocation(): StockLocation
    {
        return $this->location;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function release(): void
    {
        $this->status = ReservationStatus::Released;
    }

    public function fulfill(): void
    {
        $this->status = ReservationStatus::Fulfilled;
    }

    public function consume(Quantity $quantity): void
    {
        $current = $this->getQuantity();

        if ($quantity->compare($current) > 0) {
            throw new \DomainException('Cannot consume more than the reserved quantity.');
        }

        $remaining = $current->subtract($quantity);
        $this->quantity = $remaining->amount();

        if ($remaining->isZero()) {
            $this->status = ReservationStatus::Fulfilled;
        }
    }
}
