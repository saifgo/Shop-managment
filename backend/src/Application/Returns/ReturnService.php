<?php

declare(strict_types=1);

namespace App\Application\Returns;

use App\Application\Documents\DocumentService;
use App\Application\Inventory\AvailabilityService;
use App\Application\Inventory\StockLedgerService;
use App\Application\Sales\DeliveryService;
use App\Application\Shared\PaginatedResult;
use App\Domain\Documents\DocumentType;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Returns\ReturnEventType;
use App\Domain\Returns\ReturnItemCondition;
use App\Domain\Returns\ReturnResolution;
use App\Domain\Returns\ReturnStateMachine;
use App\Domain\Returns\ReturnStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Returns\ReturnEvent;
use App\Infrastructure\Persistence\Entity\Returns\ReturnItem;
use App\Infrastructure\Persistence\Entity\Returns\ReturnRequest;
use App\Infrastructure\Persistence\Entity\Sales\DeliveryLine;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReturnService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private ReturnStateMachine $stateMachine,
        private StockLedgerService $stockLedgerService,
        private AvailabilityService $availabilityService,
        private DocumentService $documentService,
        private DeliveryService $deliveryService,
        private ReturnedQuantities $returnedQuantities,
    ) {
    }

    /**
     * @param array{order_id: string, reason?: string, notes?: string, items: list<array{order_item_id: string, quantity: string, delivery_line_id?: string, reason?: string}>} $payload
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var ReturnRequest|null $existing */
                $existing = $this->entityManager->getRepository(ReturnRequest::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serialize($existing);
                }
            }

            $order = $this->findOrder($user, $payload['order_id']);

            if ($payload['items'] === []) {
                throw new BadRequestHttpException('At least one return item is required.');
            }

            $returnRequest = new ReturnRequest(
                EntityId::generate(),
                $user->companyId(),
                $order->getCustomer(),
                $order,
                $this->generateReference($user->companyId()),
                $payload['reason'] ?? null,
                $payload['notes'] ?? null,
                EntityId::fromString($user->getId()),
                $idempotencyKey,
            );

            $itemsById = [];

            foreach ($order->getItems() as $item) {
                $itemsById[$item->getId()] = $item;
            }

            $claimed = $this->returnedQuantities->forOrder($order);

            foreach ($payload['items'] as $itemPayload) {
                $orderItem = $itemsById[$itemPayload['order_item_id']] ?? null;

                if (!$orderItem instanceof OrderItem) {
                    throw new BadRequestHttpException('Invalid order item.');
                }

                $quantity = Quantity::of($itemPayload['quantity']);

                if ($quantity->isZero()) {
                    throw new BadRequestHttpException(sprintf('Enter how many %s you are returning.', $orderItem->getSku()));
                }

                $returnable = ReturnedQuantities::returnable($orderItem, $claimed);

                if ($quantity->compare($returnable) > 0) {
                    throw new BadRequestHttpException(sprintf(
                        'Only %s of %s can be returned (delivered and not already in a return).',
                        $returnable->amount(),
                        $orderItem->getSku(),
                    ));
                }

                $claimed[$orderItem->getId()] = ($claimed[$orderItem->getId()] ?? Quantity::zero())->add($quantity);

                $deliveryLine = null;

                if (isset($itemPayload['delivery_line_id'])) {
                    $deliveryLine = $this->findDeliveryLine($itemPayload['delivery_line_id'], $orderItem);
                }

                $returnItem = new ReturnItem(
                    EntityId::generate(),
                    $returnRequest,
                    $orderItem,
                    $quantity,
                    $deliveryLine,
                    $itemPayload['reason'] ?? null,
                );
                $this->entityManager->persist($returnItem);
            }

            $this->recordEvent(
                $returnRequest,
                ReturnEventType::Created,
                null,
                ReturnStatus::Requested,
                'Return request submitted',
                $user,
            );
            $this->entityManager->persist($returnRequest);

            return $this->serialize($returnRequest);
        });
    }

    /** @return array<string, mixed> */
    public function approve(User $user, string $returnId, ?string $notes = null): array
    {
        return $this->transition($user, $returnId, ReturnStatus::Approved, ReturnEventType::Approved, $notes);
    }

    /** @return array<string, mixed> */
    public function receive(User $user, string $returnId, ?string $notes = null): array
    {
        return $this->transition($user, $returnId, ReturnStatus::Received, ReturnEventType::Received, $notes);
    }

    /**
     * @param list<array{return_item_id: string, condition: string, notes?: string}> $items
     *
     * @return array<string, mixed>
     */
    public function inspect(User $user, string $returnId, array $items, ?string $notes = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $returnId, $items, $notes): array {
            $returnRequest = $this->findReturn($user, $returnId);
            $this->stateMachine->assertTransition($returnRequest->getStatus(), ReturnStatus::Inspected);

            $itemsById = [];

            foreach ($returnRequest->getItems() as $item) {
                $itemsById[$item->getId()] = $item;
            }

            $location = $this->availabilityService->resolveDefaultLocation($user->companyId());

            if ($location === null) {
                throw new BadRequestHttpException('No stock location configured.');
            }

            foreach ($items as $itemPayload) {
                $returnItem = $itemsById[$itemPayload['return_item_id']] ?? null;

                if (!$returnItem instanceof ReturnItem) {
                    throw new BadRequestHttpException('Invalid return item.');
                }

                $condition = ReturnItemCondition::from($itemPayload['condition']);
                $returnItem->inspect($condition, $itemPayload['notes'] ?? null);

                $variant = $returnItem->getOrderItem()->getVariant();
                $qty = $returnItem->getQuantity()->amount();

                if ($condition === ReturnItemCondition::Sellable) {
                    $this->stockLedgerService->postMovement(
                        $user->companyId(),
                        $variant,
                        $location,
                        StockMovementType::ReturnReceipt,
                        $qty,
                        '0.0000',
                        'return_request',
                        EntityId::fromString($returnRequest->getId()),
                        $returnRequest->getReference(),
                        'Sellable return received',
                        EntityId::fromString($user->getId()),
                    );
                } elseif ($condition === ReturnItemCondition::Damaged) {
                    $this->stockLedgerService->postMovement(
                        $user->companyId(),
                        $variant,
                        $location,
                        StockMovementType::Damage,
                        bcsub('0.0000', $qty, 4),
                        '0.0000',
                        'return_request',
                        EntityId::fromString($returnRequest->getId()),
                        $returnRequest->getReference(),
                        'Damaged return scrapped',
                        EntityId::fromString($user->getId()),
                    );
                }
                // DAMAGED_IN_TRANSIT: outbound loss already reflected at shipment; audit via return event only.
            }

            $from = $returnRequest->getStatus();
            $returnRequest->transitionTo(ReturnStatus::Inspected);
            $this->recordEvent($returnRequest, ReturnEventType::Inspected, $from, ReturnStatus::Inspected, $notes, $user);

            return $this->serialize($returnRequest);
        });
    }

    /**
     * @param array{resolution: string, invoice_id?: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function resolve(User $user, string $returnId, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $returnId, $payload, $idempotencyKey): array {
            $returnRequest = $this->findReturn($user, $returnId);
            $this->stateMachine->assertTransition($returnRequest->getStatus(), ReturnStatus::Resolved);

            $resolution = ReturnResolution::from($payload['resolution']);
            $from = $returnRequest->getStatus();
            $returnRequest->resolve($resolution);
            $returnRequest->transitionTo(ReturnStatus::Resolved);

            if ($resolution === ReturnResolution::CreditNote) {
                $invoiceId = $payload['invoice_id'] ?? $this->findInvoiceForOrder($user, $returnRequest->getOrder())?->getId();

                if ($invoiceId === null) {
                    throw new BadRequestHttpException('No issued invoice found for credit note.');
                }

                $creditNote = $this->documentService->createCreditNote(
                    $user,
                    $invoiceId,
                    $payload['notes'] ?? 'Return '.$returnRequest->getReference(),
                    $idempotencyKey,
                );
                $returnRequest->setCreditNoteId($creditNote['id']);
            }

            if (in_array($resolution, [ReturnResolution::Replacement, ReturnResolution::Exchange], true)) {
                $lines = [];

                foreach ($returnRequest->getItems() as $item) {
                    $lines[] = [
                        'order_item_id' => $item->getOrderItem()->getId(),
                        'quantity' => $item->getQuantity()->amount(),
                    ];
                }

                $delivery = $this->deliveryService->createReplacementFromReturn(
                    $user,
                    $returnRequest->getOrder()->getId(),
                    $lines,
                    'Replacement for '.$returnRequest->getReference(),
                    $idempotencyKey !== null ? 'repl-'.$idempotencyKey : null,
                );

                /** @var \App\Infrastructure\Persistence\Entity\Sales\Delivery|null $deliveryEntity */
                $deliveryEntity = $this->entityManager->getRepository(\App\Infrastructure\Persistence\Entity\Sales\Delivery::class)
                    ->find($delivery['id']);

                if ($deliveryEntity !== null) {
                    $returnRequest->setReplacementDelivery($deliveryEntity);
                }
            }

            $this->recordEvent(
                $returnRequest,
                ReturnEventType::Resolved,
                $from,
                ReturnStatus::Resolved,
                $payload['notes'] ?? 'Resolved as '.$resolution->value,
                $user,
            );

            return $this->serialize($returnRequest);
        });
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $returnId): array
    {
        return $this->serialize($this->findReturn($user, $returnId));
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function list(User $user, int $page, int $perPage, ?string $orderId = null, ?string $status = null): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ReturnRequest::class, 'r')
            ->where('r.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('r.createdAt', 'DESC');

        if ($orderId !== null) {
            $qb->andWhere('r.order = :order')->setParameter('order', $orderId);
        }

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        if ($user->isPortalUser()) {
            $qb->andWhere('r.customer = :customer')->setParameter('customer', $this->resolvePortalCustomerId($user));
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);

        /** @var list<ReturnRequest> $returns */
        $returns = $qb->getQuery()->getResult();
        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ReturnRequest::class, 'r')
            ->where('r.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getSingleScalarResult();

        $items = array_map(fn (ReturnRequest $r) => $this->serializeSummary($r), $returns);

        return new PaginatedResult($items, $page, $perPage, $total);
    }

    /** @return array<string, mixed> */
    private function transition(
        User $user,
        string $returnId,
        ReturnStatus $target,
        ReturnEventType $eventType,
        ?string $notes,
    ): array {
        return $this->unitOfWork->transactional(function () use ($user, $returnId, $target, $eventType, $notes): array {
            $returnRequest = $this->findReturn($user, $returnId);
            $from = $returnRequest->getStatus();
            $this->stateMachine->assertTransition($from, $target);
            $returnRequest->transitionTo($target);
            $this->recordEvent($returnRequest, $eventType, $from, $target, $notes, $user);

            return $this->serialize($returnRequest);
        });
    }

    private function recordEvent(
        ReturnRequest $returnRequest,
        ReturnEventType $eventType,
        ?ReturnStatus $from,
        ?ReturnStatus $to,
        ?string $notes,
        User $user,
    ): void {
        $event = new ReturnEvent(
            EntityId::generate(),
            $returnRequest,
            $eventType,
            $from,
            $to,
            $notes,
            EntityId::fromString($user->getId()),
        );
        $this->entityManager->persist($event);
    }

    private function findReturn(User $user, string $returnId): ReturnRequest
    {
        /** @var ReturnRequest|null $returnRequest */
        $returnRequest = $this->entityManager->getRepository(ReturnRequest::class)->findOneBy([
            'id' => $returnId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($returnRequest === null) {
            throw new NotFoundHttpException('Return request not found.');
        }

        if ($user->isPortalUser() && $returnRequest->getCustomer()->getId() !== $this->resolvePortalCustomerId($user)) {
            throw new NotFoundHttpException('Return request not found.');
        }

        return $returnRequest;
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

        if ($user->isPortalUser() && $order->getCustomer()->getId() !== $this->resolvePortalCustomerId($user)) {
            throw new NotFoundHttpException('Order not found.');
        }

        return $order;
    }

    private function findDeliveryLine(string $deliveryLineId, OrderItem $orderItem): DeliveryLine
    {
        /** @var DeliveryLine|null $line */
        $line = $this->entityManager->getRepository(DeliveryLine::class)->find($deliveryLineId);

        if ($line === null || $line->getOrderItem()->getId() !== $orderItem->getId()) {
            throw new BadRequestHttpException('Invalid delivery line for order item.');
        }

        return $line;
    }

    private function findInvoiceForOrder(User $user, Order $order): ?CommercialDocument
    {
        /** @var CommercialDocument|null $invoice */
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.order = :order')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('order', $order)
            ->setParameter('type', DocumentType::Invoice)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $invoice;
    }

    private function resolvePortalCustomerId(User $user): string
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new BadRequestHttpException('Portal customer profile not found.');
        }

        return $portalUser->getCustomer()->getId();
    }

    private function generateReference(EntityId $companyId): string
    {
        $prefix = 'RET-'.date('Ymd').'-';
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ReturnRequest::class, 'r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.reference LIKE :prefix')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%04d', $prefix, $count + 1);
    }

    /** @return array<string, mixed> */
    private function serializeSummary(ReturnRequest $returnRequest): array
    {
        return [
            'id' => $returnRequest->getId(),
            'reference' => $returnRequest->getReference(),
            'status' => $returnRequest->getStatus()->value,
            'resolution' => $returnRequest->getResolution()?->value,
            'order_id' => $returnRequest->getOrder()->getId(),
            'customer_id' => $returnRequest->getCustomer()->getId(),
            'reason' => $returnRequest->getReason(),
            'created_at' => $returnRequest->getCreatedAt()->format(DATE_ATOM),
            'resolved_at' => $returnRequest->getResolvedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(ReturnRequest $returnRequest): array
    {
        $items = [];

        foreach ($returnRequest->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'order_item_id' => $item->getOrderItem()->getId(),
                'delivery_line_id' => $item->getDeliveryLine()?->getId(),
                'sku' => $item->getOrderItem()->getSku(),
                'product_name' => $item->getOrderItem()->getProductName(),
                'quantity' => $item->getQuantity()->amount(),
                'reason' => $item->getReason(),
                'condition' => $item->getCondition()?->value,
                'inspection_notes' => $item->getInspectionNotes(),
            ];
        }

        $events = [];

        foreach ($returnRequest->getEvents() as $event) {
            $events[] = [
                'id' => $event->getId(),
                'event_type' => $event->getEventType()->value,
                'from_status' => $event->getFromStatus()?->value,
                'to_status' => $event->getToStatus()?->value,
                'notes' => $event->getNotes(),
                'created_by' => $event->getCreatedBy(),
                'created_at' => $event->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        return [
            ...$this->serializeSummary($returnRequest),
            'notes' => $returnRequest->getNotes(),
            'credit_note_id' => $returnRequest->getCreditNoteId(),
            'replacement_delivery_id' => $returnRequest->getReplacementDelivery()?->getId(),
            'items' => $items,
            'events' => $events,
        ];
    }
}
