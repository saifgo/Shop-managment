<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\BackorderPolicy;
use App\Domain\Inventory\DemandStatus;
use App\Domain\Inventory\ReservationStatus;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Sales\OrderLineStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Sales\DemandAllocation;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Infrastructure\Persistence\Entity\Sales\Reservation;
use Doctrine\ORM\EntityManagerInterface;

final class ReservationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockLedgerService $stockLedgerService,
        private AvailabilityService $availabilityService,
    ) {
    }

    public function reserveOrder(Order $order, ?EntityId $actorUserId = null): void
    {
        $companyId = $order->companyId();
        $location = $this->availabilityService->requireDefaultLocation($companyId);

        foreach ($order->getItems() as $item) {
            $this->reserveLine($order, $item, $location, $companyId, $actorUserId);
        }
    }

    private function reserveLine(
        Order $order,
        OrderItem $item,
        $location,
        EntityId $companyId,
        ?EntityId $actorUserId,
    ): void {
        $variant = $item->getVariant();
        $product = $variant->getProduct();
        $ordered = $item->getQuantityOrdered();

        $availability = $this->availabilityService->forVariant($companyId, $variant, $location);
        $available = Quantity::of($availability['available_to_sell']);
        $reserveQty = $ordered->min($available);
        $backorderQty = $ordered->subtract($reserveQty);

        if (!$backorderQty->isZero() && $product->getBackorderPolicy() === BackorderPolicy::Deny) {
            throw new \DomainException(sprintf(
                'Insufficient stock for %s (%s). Backorders are not allowed.',
                $item->getVariantName(),
                $item->getSku(),
            ));
        }

        $this->releaseExistingAllocations($item, $companyId, $location, $actorUserId);

        if (!$reserveQty->isZero()) {
            $reservation = new Reservation(
                EntityId::generate(),
                $companyId,
                $item,
                $variant,
                $location,
                $reserveQty,
            );
            $this->entityManager->persist($reservation);

            $this->stockLedgerService->postMovement(
                companyId: $companyId,
                variant: $variant,
                location: $location,
                movementType: StockMovementType::SaleReservation,
                quantityDelta: '0.0000',
                reservedDelta: $reserveQty->amount(),
                sourceType: 'reservation',
                sourceId: EntityId::fromString($reservation->getId()),
                reference: $order->getReference(),
                notes: sprintf('Reserved for order %s', $order->getReference()),
                createdBy: $actorUserId,
            );
        }

        if (!$backorderQty->isZero()) {
            $demand = new DemandAllocation(
                EntityId::generate(),
                $companyId,
                $item,
                $variant,
                $order->getCustomer(),
                $backorderQty,
            );
            $this->entityManager->persist($demand);
        }

        $lineStatus = $this->resolveLineStatus($reserveQty, $backorderQty, $ordered);
        $item->applyAllocation($reserveQty, $backorderQty, $lineStatus);
    }

    private function resolveLineStatus(Quantity $reserved, Quantity $backordered, Quantity $ordered): OrderLineStatus
    {
        if ($backordered->isZero() && $reserved->equals($ordered)) {
            return OrderLineStatus::Reserved;
        }

        if ($reserved->isZero() && !$backordered->isZero()) {
            return OrderLineStatus::Backordered;
        }

        if (!$backordered->isZero()) {
            return OrderLineStatus::Backordered;
        }

        return OrderLineStatus::Reserved;
    }

    public function releaseOrder(Order $order, ?EntityId $actorUserId = null): void
    {
        foreach ($order->getItems() as $item) {
            $this->releaseExistingAllocations(
                $item,
                $order->companyId(),
                $this->availabilityService->requireDefaultLocation($order->companyId()),
                $actorUserId,
                $order->getReference(),
            );
            $item->clearAllocation();
        }
    }

    private function releaseExistingAllocations(
        OrderItem $item,
        EntityId $companyId,
        $location,
        ?EntityId $actorUserId,
        ?string $orderReference = null,
    ): void {
        /** @var list<Reservation> $reservations */
        $reservations = $this->entityManager->getRepository(Reservation::class)->findBy([
            'orderItem' => $item,
            'status' => ReservationStatus::Active,
        ]);

        foreach ($reservations as $reservation) {
            $reservation->release();

            if ($location !== null) {
                $this->stockLedgerService->postMovement(
                    companyId: $companyId,
                    variant: $reservation->getVariant(),
                    location: $location,
                    movementType: StockMovementType::SaleReservation,
                    quantityDelta: '0.0000',
                    reservedDelta: '-'.$reservation->getQuantity()->amount(),
                    sourceType: 'reservation_release',
                    sourceId: EntityId::fromString($reservation->getId()),
                    reference: $orderReference,
                    notes: 'Reservation released',
                    createdBy: $actorUserId,
                );
            }
        }

        /** @var list<DemandAllocation> $demands */
        $demands = $this->entityManager->getRepository(DemandAllocation::class)->findBy([
            'orderItem' => $item,
            'status' => [DemandStatus::Open, DemandStatus::PartiallyFulfilled],
        ]);

        foreach ($demands as $demand) {
            $demand->cancel();
        }
    }

    public function consumeShipment(
        OrderItem $item,
        Quantity $quantity,
        EntityId $deliveryId,
        EntityId $companyId,
        ?EntityId $actorUserId = null,
        ?string $deliveryReference = null,
        bool $isReplacement = false,
    ): void {
        $location = $this->availabilityService->requireDefaultLocation($companyId);

        /** @var list<Reservation> $reservations */
        $reservations = $this->entityManager->getRepository(Reservation::class)->findBy([
            'orderItem' => $item,
            'status' => ReservationStatus::Active,
        ]);

        $remaining = $quantity;

        foreach ($reservations as $reservation) {
            if ($remaining->isZero()) {
                break;
            }

            $consumeQty = $remaining->min($reservation->getQuantity());
            $reservation->consume($consumeQty);
            $remaining = $remaining->subtract($consumeQty);
        }

        $this->stockLedgerService->postMovement(
            companyId: $companyId,
            variant: $item->getVariant(),
            location: $location,
            movementType: StockMovementType::SaleShipment,
            quantityDelta: '-'.$quantity->amount(),
            reservedDelta: $isReplacement ? '0.0000' : '-'.$quantity->amount(),
            sourceType: $isReplacement ? 'replacement_delivery' : 'delivery',
            sourceId: $deliveryId,
            reference: $deliveryReference,
            notes: sprintf('Shipment for delivery %s', $deliveryReference ?? $deliveryId->toString()),
            createdBy: $actorUserId,
        );
    }
}
