<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\ReservationService;
use App\Application\Shared\PaginatedResult;
use App\Domain\Sales\DeliveryStateMachine;
use App\Domain\Sales\DeliveryStatus;
use App\Domain\Sales\OrderStateMachine;
use App\Domain\Sales\OrderStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Sales\Delivery;
use App\Infrastructure\Persistence\Entity\Sales\DeliveryLine;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeliveryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private DeliveryStateMachine $deliveryStateMachine,
        private OrderStateMachine $orderStateMachine,
        private ReservationService $reservationService,
    ) {
    }

    /**
     * @param list<array{order_item_id: string, quantity: string}> $lines
     *
     * @return array<string, mixed>
     */
    public function createFromOrder(
        User $user,
        string $orderId,
        array $lines,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): array {
        return $this->unitOfWork->transactional(function () use ($user, $orderId, $lines, $notes, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var Delivery|null $existing */
                $existing = $this->entityManager->getRepository(Delivery::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializeDelivery($existing);
                }
            }

            $order = $this->findOrder($user, $orderId);

            if (!in_array($order->getStatus(), [
                OrderStatus::ReadyToDeliver,
                OrderStatus::PartiallyAllocated,
                OrderStatus::PartiallyDelivered,
            ], true)) {
                throw new BadRequestHttpException('Order is not ready for delivery.');
            }

            if ($lines === []) {
                throw new BadRequestHttpException('At least one delivery line is required.');
            }

            $reference = $this->generateReference($user->companyId());
            $delivery = new Delivery(
                EntityId::generate(),
                $user->companyId(),
                $order,
                $reference,
                $notes,
                EntityId::fromString($user->getId()),
                $idempotencyKey,
            );

            $itemsById = [];

            foreach ($order->getItems() as $item) {
                $itemsById[$item->getId()] = $item;
            }

            foreach ($lines as $linePayload) {
                $orderItem = $itemsById[$linePayload['order_item_id']] ?? null;

                if (!$orderItem instanceof OrderItem) {
                    throw new BadRequestHttpException('Invalid order item in delivery lines.');
                }

                $quantity = Quantity::of($linePayload['quantity']);
                $remaining = $orderItem->getRemainingDeliverableQuantity();

                if ($quantity->compare($remaining) > 0) {
                    throw new BadRequestHttpException(sprintf(
                        'Cannot deliver %s of %s; only %s remaining.',
                        $quantity->amount(),
                        $orderItem->getSku(),
                        $remaining->amount(),
                    ));
                }

                $deliveryLine = new DeliveryLine(
                    EntityId::generate(),
                    $delivery,
                    $orderItem,
                    $quantity,
                );
                $this->entityManager->persist($deliveryLine);
            }

            $this->entityManager->persist($delivery);

            return $this->serializeDelivery($delivery);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(User $user, string $deliveryId, string $targetStatus): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $deliveryId, $targetStatus): array {
            $delivery = $this->findDelivery($user, $deliveryId);
            $status = DeliveryStatus::from($targetStatus);
            $this->deliveryStateMachine->assertTransition($delivery->getStatus(), $status);

            if ($status === DeliveryStatus::Dispatched) {
                $this->dispatchDelivery($delivery, $user);
            }

            if ($status === DeliveryStatus::Delivered) {
                $this->completeDelivery($delivery, $user);
            }

            $delivery->transitionTo($status);
            $this->updateOrderDeliveryStatus($delivery->getOrder());

            return $this->serializeDelivery($delivery);
        });
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function list(User $user, int $page, int $perPage, ?string $orderId = null): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Delivery::class, 'd')
            ->where('d.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('d.createdAt', 'DESC');

        if ($orderId !== null) {
            $qb->andWhere('d.order = :order')->setParameter('order', $orderId);
        }

        if ($user->isPortalUser()) {
            $customerId = $this->resolvePortalCustomerId($user);
            $qb->join('d.order', 'o')
                ->andWhere('o.customer = :customer')
                ->setParameter('customer', $customerId);
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $items = [];

        foreach ($paginator as $delivery) {
            if ($delivery instanceof Delivery) {
                $items[] = $this->serializeDeliverySummary($delivery);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $deliveryId): array
    {
        return $this->serializeDelivery($this->findDelivery($user, $deliveryId));
    }

    private function dispatchDelivery(Delivery $delivery, User $user): void
    {
        if ($delivery->getStatus() === DeliveryStatus::Dispatched) {
            return;
        }

        foreach ($delivery->getLines() as $line) {
            $isReplacement = str_contains((string) $delivery->getNotes(), 'Replacement');
            $this->reservationService->consumeShipment(
                $line->getOrderItem(),
                $line->getQuantity(),
                EntityId::fromString($delivery->getId()),
                $delivery->companyId(),
                EntityId::fromString($user->getId()),
                $delivery->getReference(),
                $isReplacement,
            );
        }
    }

    private function completeDelivery(Delivery $delivery, User $user): void
    {
        $isReplacement = str_contains((string) $delivery->getNotes(), 'Replacement');

        if (!$isReplacement) {
            foreach ($delivery->getLines() as $line) {
                $line->getOrderItem()->recordDelivery($line->getQuantity());
            }

            $this->updateOrderDeliveryStatus($delivery->getOrder());
        }
    }

    private function updateOrderDeliveryStatus(Order $order): void
    {
        $allDelivered = true;
        $anyDelivered = false;

        foreach ($order->getItems() as $item) {
            if (!$item->getQuantityDelivered()->isZero()) {
                $anyDelivered = true;
            }

            if (!$item->getQuantityDelivered()->equals($item->getQuantityOrdered())) {
                $allDelivered = false;
            }
        }

        if ($allDelivered) {
            if ($order->getStatus() !== OrderStatus::Delivered) {
                $this->orderStateMachine->assertTransition($order->getStatus(), OrderStatus::Delivered);
                $order->transitionTo(OrderStatus::Delivered, null, 'All items delivered');
            }

            return;
        }

        if ($anyDelivered && $order->getStatus() !== OrderStatus::PartiallyDelivered) {
            if ($this->orderStateMachine->canTransition($order->getStatus(), OrderStatus::PartiallyDelivered)) {
                $order->transitionTo(OrderStatus::PartiallyDelivered, null, 'Partial delivery completed');
            }
        }
    }

    private function findOrder(User $user, string $orderId): Order
    {
        /** @var Order|null $order */
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'id' => $orderId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($order === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        if ($user->isPortalUser()) {
            $customerId = $this->resolvePortalCustomerId($user);

            if ($order->getCustomer()->getId() !== $customerId) {
                throw new NotFoundHttpException('Order not found.');
            }
        }

        return $order;
    }

    private function findDelivery(User $user, string $deliveryId): Delivery
    {
        /** @var Delivery|null $delivery */
        $delivery = $this->entityManager->getRepository(Delivery::class)->findOneBy([
            'id' => $deliveryId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($delivery === null) {
            throw new NotFoundHttpException('Delivery not found.');
        }

        if ($user->isPortalUser()) {
            $customerId = $this->resolvePortalCustomerId($user);

            if ($delivery->getOrder()->getCustomer()->getId() !== $customerId) {
                throw new NotFoundHttpException('Delivery not found.');
            }
        }

        return $delivery;
    }

    private function resolvePortalCustomerId(User $user): string
    {
        /** @var \App\Infrastructure\Persistence\Entity\Customer\PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(\App\Infrastructure\Persistence\Entity\Customer\PortalUser::class)
            ->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new BadRequestHttpException('Portal customer profile not found.');
        }

        return $portalUser->getCustomer()->getId();
    }

    private function generateReference(EntityId $companyId): string
    {
        $prefix = 'DEL-'.date('Ymd').'-';
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Delivery::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.reference LIKE :prefix')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%04d', $prefix, $count + 1);
    }

    /** @return array<string, mixed> */
    private function serializeDeliverySummary(Delivery $delivery): array
    {
        return [
            'id' => $delivery->getId(),
            'reference' => $delivery->getReference(),
            'status' => $delivery->getStatus()->value,
            'order_id' => $delivery->getOrder()->getId(),
            'order_reference' => $delivery->getOrder()->getReference(),
            'created_at' => $delivery->getCreatedAt()->format(DATE_ATOM),
            'dispatched_at' => $delivery->getDispatchedAt()?->format(DATE_ATOM),
            'delivered_at' => $delivery->getDeliveredAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function createReplacementFromReturn(
        User $user,
        string $orderId,
        array $lines,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): array {
        return $this->unitOfWork->transactional(function () use ($user, $orderId, $lines, $notes, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var Delivery|null $existing */
                $existing = $this->entityManager->getRepository(Delivery::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializeDelivery($existing);
                }
            }

            $order = $this->findOrder($user, $orderId);

            if ($lines === []) {
                throw new BadRequestHttpException('At least one replacement line is required.');
            }

            $reference = $this->generateReference($user->companyId());
            $delivery = new Delivery(
                EntityId::generate(),
                $user->companyId(),
                $order,
                $reference,
                $notes ?? 'Replacement shipment',
                EntityId::fromString($user->getId()),
                $idempotencyKey,
            );

            $itemsById = [];

            foreach ($order->getItems() as $item) {
                $itemsById[$item->getId()] = $item;
            }

            foreach ($lines as $linePayload) {
                $orderItem = $itemsById[$linePayload['order_item_id']] ?? null;

                if (!$orderItem instanceof OrderItem) {
                    throw new BadRequestHttpException('Invalid order item in replacement lines.');
                }

                $quantity = Quantity::of($linePayload['quantity']);
                $deliveryLine = new DeliveryLine(
                    EntityId::generate(),
                    $delivery,
                    $orderItem,
                    $quantity,
                );
                $this->entityManager->persist($deliveryLine);
            }

            $this->entityManager->persist($delivery);

            return $this->serializeDelivery($delivery);
        });
    }

    /** @return array<string, mixed> */
    private function serializeDelivery(Delivery $delivery): array
    {
        $lines = [];

        foreach ($delivery->getLines() as $line) {
            $orderItem = $line->getOrderItem();
            $lines[] = [
                'id' => $line->getId(),
                'order_item_id' => $orderItem->getId(),
                'product_name' => $orderItem->getProductName(),
                'variant_name' => $orderItem->getVariantName(),
                'sku' => $orderItem->getSku(),
                'quantity' => $line->getQuantity()->amount(),
            ];
        }

        return [
            ...$this->serializeDeliverySummary($delivery),
            'notes' => $delivery->getNotes(),
            'tracking_reference' => $delivery->getTrackingReference(),
            'lines' => $lines,
        ];
    }
}
