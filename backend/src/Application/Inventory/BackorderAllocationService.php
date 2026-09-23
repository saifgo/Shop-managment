<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\DemandStatus;
use App\Domain\Inventory\ReservationStatus;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Sales\OrderLineStatus;
use App\Domain\Sales\OrderStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use App\Infrastructure\Persistence\Entity\Sales\DemandAllocation;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\Reservation;
use Doctrine\ORM\EntityManagerInterface;

final class BackorderAllocationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockLedgerService $stockLedgerService,
        private AvailabilityService $availabilityService,
    ) {
    }

    /**
     * Allocate newly available stock to eligible backorders (oldest first by priority).
     *
     * @return array{allocated: string, allocations: list<array{demand_id: string, quantity: string}>}
     */
    public function allocateToBackorders(
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
        ?EntityId $actorUserId = null,
        ?string $reference = null,
    ): array {
        $availability = $this->availabilityService->forVariant($companyId, $variant, $location);
        $remaining = Quantity::of($availability['available_to_sell']);
        $results = [];

        if ($remaining->isZero()) {
            return ['allocated' => '0.0000', 'allocations' => []];
        }

        /** @var list<DemandAllocation> $demands */
        $demands = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(DemandAllocation::class, 'd')
            ->join('d.orderItem', 'oi')
            ->join('oi.order', 'o')
            ->where('d.companyId = :companyId')
            ->andWhere('d.variant = :variant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('o.status NOT IN (:cancelled)')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('variant', $variant)
            ->setParameter('statuses', [DemandStatus::Open->value, DemandStatus::PartiallyFulfilled->value])
            ->setParameter('cancelled', [OrderStatus::Cancelled->value])
            ->orderBy('d.priority', 'ASC')
            ->addOrderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $totalAllocated = Quantity::zero();

        foreach ($demands as $demand) {
            if ($remaining->isZero()) {
                break;
            }

            $fulfilled = $demand->fulfill($remaining);
            if ($fulfilled->isZero()) {
                continue;
            }

            $orderItem = $demand->getOrderItem();
            $orderItem->fulfillBackorder($fulfilled);

            $reservation = new Reservation(
                EntityId::generate(),
                $companyId,
                $orderItem,
                $variant,
                $location,
                $fulfilled,
            );
            $this->entityManager->persist($reservation);

            $this->stockLedgerService->postMovement(
                companyId: $companyId,
                variant: $variant,
                location: $location,
                movementType: StockMovementType::SaleReservation,
                quantityDelta: '0.0000',
                reservedDelta: $fulfilled->amount(),
                sourceType: 'backorder_allocation',
                sourceId: EntityId::fromString($demand->getId()),
                reference: $reference,
                notes: sprintf('Backorder fulfilled for order %s', $orderItem->getOrder()->getReference()),
                createdBy: $actorUserId,
            );

            $this->updateOrderStatusAfterAllocation($orderItem->getOrder());

            $remaining = $remaining->subtract($fulfilled);
            $totalAllocated = $totalAllocated->add($fulfilled);
            $results[] = [
                'demand_id' => $demand->getId(),
                'quantity' => $fulfilled->amount(),
            ];
        }

        return [
            'allocated' => $totalAllocated->amount(),
            'allocations' => $results,
        ];
    }

    private function updateOrderStatusAfterAllocation(Order $order): void
    {
        $hasBackorder = false;
        $allReady = true;

        foreach ($order->getItems() as $item) {
            if (!$item->getQuantityBackordered()->isZero()) {
                $hasBackorder = true;
            }

            if ($item->getLineStatus() !== OrderLineStatus::Ready) {
                $allReady = false;
            }
        }

        if (!$hasBackorder && $allReady && in_array($order->getStatus(), [OrderStatus::Confirmed, OrderStatus::PartiallyAllocated], true)) {
            $order->transitionTo(OrderStatus::ReadyToDeliver, null, 'All lines ready after backorder allocation');
        }
    }
}
