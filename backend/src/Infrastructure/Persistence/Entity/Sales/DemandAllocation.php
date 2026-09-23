<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Sales;

use App\Domain\Inventory\DemandStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'demand_allocations')]
#[ORM\Index(name: 'IDX_DEMAND_CUSTOMER', columns: ['customer_id'])]
#[ORM\Index(name: 'IDX_DEMAND_VARIANT', columns: ['variant_id'])]
class DemandAllocation implements CompanyScoped
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

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    #[ORM\Column(name: 'fulfilled_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $fulfilledQuantity = '0.0000';

    #[ORM\Column(length: 32, enumType: DemandStatus::class)]
    private DemandStatus $status;

    #[ORM\Column(options: ['default' => 100])]
    private int $priority = 100;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        OrderItem $orderItem,
        ProductVariant $variant,
        Customer $customer,
        Quantity $quantity,
        int $priority = 100,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->orderItem = $orderItem;
        $this->variant = $variant;
        $this->customer = $customer;
        $this->quantity = $quantity->amount();
        $this->status = DemandStatus::Open;
        $this->priority = $priority;
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

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function getOpenQuantity(): Quantity
    {
        $open = bcsub($this->quantity, $this->fulfilledQuantity, 4);

        return Quantity::of($open);
    }

    public function getStatus(): DemandStatus
    {
        return $this->status;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function cancel(): void
    {
        $this->status = DemandStatus::Cancelled;
    }

    public function fulfill(Quantity $quantity): Quantity
    {
        $open = $this->getOpenQuantity();
        $fulfilled = $quantity->min($open);

        if ($fulfilled->isZero()) {
            return $fulfilled;
        }

        $this->fulfilledQuantity = bcadd($this->fulfilledQuantity, $fulfilled->amount(), 4);

        if ($fulfilled->equals($open)) {
            $this->status = DemandStatus::Fulfilled;
        } else {
            $this->status = DemandStatus::PartiallyFulfilled;
        }

        return $fulfilled;
    }
}
