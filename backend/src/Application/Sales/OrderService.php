<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\ReservationService;
use App\Application\Returns\ReturnedQuantities;
use App\Application\Shared\PaginatedResult;
use App\Domain\Sales\OrderLineStatus;
use App\Domain\Sales\OrderStateMachine;
use App\Domain\Sales\OrderStatus;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CartService $cartService,
        private ReservationService $reservationService,
        private OrderStateMachine $orderStateMachine,
        private UnitOfWork $unitOfWork,
        private OpenDeliveryQuantities $openDeliveryQuantities,
        private ReturnedQuantities $returnedQuantities,
    ) {
    }

    /**
     * @param list<array{variant_id: string, quantity: string}> $items
     *
     * @return array<string, mixed>
     */
    public function create(
        User $user,
        array $items,
        ?string $customerId = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): array {
        return $this->unitOfWork->transactional(function () use ($user, $items, $customerId, $notes, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var Order|null $existing */
                $existing = $this->entityManager->getRepository(Order::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializeOrder($existing, $user);
                }
            }

            $validation = $this->cartService->validate($user, $items, $customerId);

            if (!$validation['valid']) {
                throw new BadRequestHttpException('Cart contains items that cannot be ordered due to stock policy.');
            }

            $customer = $this->resolveCustomer($user, $validation['customer_id'] ?? $customerId);
            $reference = $this->generateReference($user->companyId());

            $order = new Order(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                customer: $customer,
                reference: $reference,
                status: OrderStatus::Submitted,
                currency: $validation['currency'],
                subtotal: $this->moneyFromPayload($validation['subtotal']),
                taxTotal: $this->moneyFromPayload($validation['tax_total']),
                discountTotal: $this->moneyFromPayload($validation['discount_total']),
                grandTotal: $this->moneyFromPayload($validation['grand_total']),
                notes: $notes,
                idempotencyKey: $idempotencyKey,
                createdBy: EntityId::fromString($user->getId()),
            );

            foreach ($validation['lines'] as $index => $line) {
                $variant = $this->entityManager->getReference(
                    \App\Infrastructure\Persistence\Entity\Catalog\ProductVariant::class,
                    $line['variant_id'],
                );

                $item = new OrderItem(
                    id: EntityId::generate(),
                    order: $order,
                    variant: $variant,
                    productId: $line['product_id'],
                    productName: $line['product_name'],
                    variantName: $line['variant_name'],
                    sku: $line['sku'],
                    quantityOrdered: \App\Domain\Shared\Quantity::of($line['quantity']),
                    unitPrice: $this->moneyFromPayload($line['unit_price']),
                    taxRate: $line['tax_rate'],
                    discountAmount: $this->moneyFromPayload($line['discount_amount']),
                    lineSubtotal: $this->moneyFromPayload($line['line_subtotal']),
                    lineTax: $this->moneyFromPayload($line['line_tax']),
                    lineTotal: $this->moneyFromPayload($line['line_total']),
                    sortOrder: $index,
                );
                $this->entityManager->persist($item);
            }

            $this->entityManager->persist($order);

            return $this->serializeOrder($order, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(User $user, string $orderId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $orderId): array {
            $order = $this->findOrder($user, $orderId);
            $this->orderStateMachine->assertTransition($order->getStatus(), OrderStatus::Confirmed);
            $order->transitionTo(OrderStatus::Confirmed, EntityId::fromString($user->getId()), 'Order confirmed');
            $this->reservationService->reserveOrder($order, EntityId::fromString($user->getId()));
            $this->applyOrderAllocationStatus($order);
            $order->transitionTo(
                $this->resolvePostReservationStatus($order),
                EntityId::fromString($user->getId()),
                'Allocation complete',
            );

            return $this->serializeOrder($order, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reserve(User $user, string $orderId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $orderId): array {
            $order = $this->findOrder($user, $orderId);

            if (!in_array($order->getStatus(), [OrderStatus::Confirmed, OrderStatus::PartiallyAllocated, OrderStatus::ReadyToDeliver], true)) {
                throw new BadRequestHttpException('Order must be confirmed before reserving stock.');
            }

            $this->reservationService->reserveOrder($order, EntityId::fromString($user->getId()));
            $this->applyOrderAllocationStatus($order);
            $targetStatus = $this->resolvePostReservationStatus($order);

            if ($order->getStatus() !== $targetStatus) {
                $this->orderStateMachine->assertTransition($order->getStatus(), $targetStatus);
                $order->transitionTo($targetStatus, EntityId::fromString($user->getId()), 'Re-reserved stock');
            }

            return $this->serializeOrder($order, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(User $user, string $orderId, ?string $reason = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $orderId, $reason): array {
            $order = $this->findOrder($user, $orderId);

            if (in_array($order->getStatus(), [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
                throw new BadRequestHttpException('Order cannot be cancelled.');
            }

            $this->orderStateMachine->assertTransition($order->getStatus(), OrderStatus::Cancelled);
            $this->reservationService->releaseOrder($order, EntityId::fromString($user->getId()));
            $order->transitionTo(OrderStatus::Cancelled, EntityId::fromString($user->getId()), $reason ?? 'Order cancelled');

            return $this->serializeOrder($order, $user);
        });
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function list(
        User $user,
        int $page,
        int $perPage,
        ?string $status = null,
        ?string $customerId = null,
        ?string $search = null,
    ): PaginatedResult {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.customer', 'c')
            ->where('o.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('o.createdAt', 'DESC');

        if ($user->isPortalUser()) {
            $customer = $this->resolvePortalCustomer($user);
            $qb->andWhere('o.customer = :customer')->setParameter('customer', $customer);
        } elseif ($customerId !== null) {
            $qb->andWhere('o.customer = :customer')->setParameter('customer', $customerId);
        }

        // Accepts a single status or a comma-separated list (e.g. "SUBMITTED,CONFIRMED").
        if ($status !== null && $status !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $status))));
            $qb->andWhere('o.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('LOWER(o.reference) LIKE :search OR LOWER(c.displayName) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($search)).'%');
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $items = [];

        foreach ($paginator as $order) {
            if ($order instanceof Order) {
                $items[] = $this->serializeOrderSummary($order);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $user, string $orderId): array
    {
        return $this->serializeOrder($this->findOrder($user, $orderId), $user);
    }

    private function applyOrderAllocationStatus(Order $order): void
    {
        foreach ($order->getItems() as $item) {
            if ($item->getLineStatus() === OrderLineStatus::Reserved && !$item->getQuantityBackordered()->isZero()) {
                continue;
            }

            if ($item->getLineStatus() === OrderLineStatus::Reserved) {
                $item->setLineStatus(OrderLineStatus::Ready);
            }
        }
    }

    private function resolvePostReservationStatus(Order $order): OrderStatus
    {
        $hasBackorder = false;
        $allReady = true;

        foreach ($order->getItems() as $item) {
            if (!$item->getQuantityBackordered()->isZero()) {
                $hasBackorder = true;
            }

            if ($item->getLineStatus() !== OrderLineStatus::Ready && $item->getLineStatus() !== OrderLineStatus::Reserved) {
                $allReady = false;
            }
        }

        if ($hasBackorder) {
            return OrderStatus::PartiallyAllocated;
        }

        return $allReady ? OrderStatus::ReadyToDeliver : OrderStatus::PartiallyAllocated;
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
            $customer = $this->resolvePortalCustomer($user);

            if ($order->getCustomer()->getId() !== $customer->getId()) {
                throw new NotFoundHttpException('Order not found.');
            }
        }

        return $order;
    }

    private function resolveCustomer(User $user, ?string $customerId): Customer
    {
        if ($user->isPortalUser()) {
            return $this->resolvePortalCustomer($user);
        }

        if ($customerId === null) {
            throw new BadRequestHttpException('customer_id is required for admin order creation.');
        }

        /** @var Customer|null $customer */
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy([
            'id' => $customerId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($customer === null) {
            throw new BadRequestHttpException('Customer not found.');
        }

        return $customer;
    }

    private function resolvePortalCustomer(User $user): Customer
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new BadRequestHttpException('Portal customer profile not found.');
        }

        return $portalUser->getCustomer();
    }

    private function generateReference(EntityId $companyId): string
    {
        $prefix = 'ORD-'.date('Ymd').'-';
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.reference LIKE :prefix')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%04d', $prefix, $count + 1);
    }

    /** @param array{amount: string, currency: string} $payload */
    private function moneyFromPayload(array $payload): \App\Domain\Shared\Money
    {
        return \App\Domain\Shared\Money::of($payload['amount'], $payload['currency']);
    }

    /** @return array<string, mixed> */
    private function serializeOrderSummary(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'reference' => $order->getReference(),
            'status' => $order->getStatus()->value,
            'customer_id' => $order->getCustomer()->getId(),
            'customer_name' => $order->getCustomer()->getDisplayName(),
            'currency' => $order->getCurrency(),
            'grand_total' => ['amount' => $order->getGrandTotal()->amount(), 'currency' => $order->getCurrency()],
            'created_at' => $order->getCreatedAt()->format(DATE_ATOM),
            'confirmed_at' => $order->getConfirmedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeOrder(Order $order, User $user): array
    {
        $items = [];
        $pending = $this->openDeliveryQuantities->forOrder($order);
        $claimed = $this->returnedQuantities->forOrder($order);
        $canDeliver = in_array($order->getStatus(), [
            OrderStatus::ReadyToDeliver,
            OrderStatus::PartiallyAllocated,
            OrderStatus::PartiallyDelivered,
        ], true);
        $anyDeliverable = false;

        foreach ($order->getItems() as $item) {
            $deliverable = OpenDeliveryQuantities::deliverable($item, $pending);
            $anyDeliverable = $anyDeliverable || !$deliverable->isZero();
            $items[] = [
                'id' => $item->getId(),
                'variant_id' => $item->getVariant()->getId(),
                'product_id' => $item->getProductId(),
                'product_name' => $item->getProductName(),
                'variant_name' => $item->getVariantName(),
                'sku' => $item->getSku(),
                'quantity_ordered' => $item->getQuantityOrdered()->amount(),
                'quantity_reserved' => $item->getQuantityReserved()->amount(),
                'quantity_backordered' => $item->getQuantityBackordered()->amount(),
                'quantity_delivered' => $item->getQuantityDelivered()->amount(),
                'quantity_in_open_deliveries' => ($pending[$item->getId()] ?? \App\Domain\Shared\Quantity::zero())->amount(),
                'quantity_deliverable' => $deliverable->amount(),
                'quantity_returnable' => ReturnedQuantities::returnable($item, $claimed)->amount(),
                'line_status' => $item->getLineStatus()->value,
                'unit_price' => ['amount' => $item->getUnitPrice()->amount(), 'currency' => $item->getUnitPrice()->currency()],
                'tax_rate' => $item->getTaxRate(),
                'discount_amount' => ['amount' => $item->getDiscountAmount()->amount(), 'currency' => $item->getUnitPrice()->currency()],
                'line_subtotal' => ['amount' => $item->getLineSubtotal()->amount(), 'currency' => $item->getUnitPrice()->currency()],
                'line_tax' => ['amount' => $item->getLineTax()->amount(), 'currency' => $item->getUnitPrice()->currency()],
                'line_total' => ['amount' => $item->getLineTotal()->amount(), 'currency' => $item->getUnitPrice()->currency()],
            ];
        }

        $history = [];

        foreach ($order->getStatusHistory() as $entry) {
            $history[] = [
                'from_status' => $entry->getFromStatus()->value,
                'to_status' => $entry->getToStatus()->value,
                'reason' => $entry->getReason(),
                'created_at' => $entry->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        return [
            ...$this->serializeOrderSummary($order),
            'notes' => $order->getNotes(),
            'subtotal' => ['amount' => $order->getSubtotal()->amount(), 'currency' => $order->getCurrency()],
            'tax_total' => ['amount' => $order->getTaxTotal()->amount(), 'currency' => $order->getCurrency()],
            'discount_total' => ['amount' => $order->getDiscountTotal()->amount(), 'currency' => $order->getCurrency()],
            'submitted_at' => $order->getSubmittedAt()?->format(DATE_ATOM),
            'items' => $items,
            'status_history' => $history,
            'can_confirm' => !$user->isPortalUser() && $order->getStatus() === OrderStatus::Submitted,
            'can_cancel' => !in_array($order->getStatus(), [OrderStatus::Delivered, OrderStatus::Cancelled], true),
            'can_reserve' => !$user->isPortalUser() && in_array($order->getStatus(), [OrderStatus::Confirmed, OrderStatus::PartiallyAllocated, OrderStatus::ReadyToDeliver], true),
            'can_create_delivery' => !$user->isPortalUser() && $canDeliver && $anyDeliverable,
        ];
    }
}
