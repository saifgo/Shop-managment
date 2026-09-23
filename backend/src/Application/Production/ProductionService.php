<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Inventory\AvailabilityService;
use App\Application\Inventory\BackorderAllocationService;
use App\Application\Inventory\StockLedgerService;
use App\Application\Shared\PaginatedResult;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Production\ProductionPriority;
use App\Domain\Production\ProductionStateMachine;
use App\Domain\Production\ProductionStatus;
use App\Domain\Production\StageExecutionStatus;
use App\Domain\Production\StageQuantityReconciler;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Production\ProductionItem;
use App\Infrastructure\Persistence\Entity\Production\ProductionLoss;
use App\Infrastructure\Persistence\Entity\Production\ProductionLossReason;
use App\Infrastructure\Persistence\Entity\Production\ProductionOrder;
use App\Infrastructure\Persistence\Entity\Production\ProductionStage;
use App\Infrastructure\Persistence\Entity\Production\StageExecution;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductionStateMachine $stateMachine,
        private StageQuantityReconciler $quantityReconciler,
        private StockLedgerService $stockLedgerService,
        private BackorderAllocationService $backorderAllocationService,
        private AvailabilityService $availabilityService,
        private UnitOfWork $unitOfWork,
    ) {
    }

    /**
     * @param array{
     *     variant_id: string,
     *     planned_quantity: string,
     *     priority?: string,
     *     planned_start?: string|null,
     *     planned_due?: string|null,
     *     notes?: string|null,
     *     plan?: bool,
     *     source_type?: string|null,
     *     source_id?: string|null
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var ProductionOrder|null $existing */
                $existing = $this->entityManager->getRepository(ProductionOrder::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serialize($existing);
                }
            }

            $variant = $this->findVariant($user, $payload['variant_id']);
            $reference = $this->generateReference($user->companyId());
            $priority = ProductionPriority::tryFrom(strtoupper($payload['priority'] ?? 'NORMAL')) ?? ProductionPriority::Normal;

            $order = new ProductionOrder(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                reference: $reference,
                priority: $priority,
                sourceType: $payload['source_type'] ?? null,
                sourceId: isset($payload['source_id']) ? EntityId::fromString($payload['source_id']) : null,
                plannedStart: isset($payload['planned_start']) ? new \DateTimeImmutable($payload['planned_start']) : null,
                plannedDue: isset($payload['planned_due']) ? new \DateTimeImmutable($payload['planned_due']) : null,
                notes: $payload['notes'] ?? null,
                createdBy: EntityId::fromString($user->getId()),
                idempotencyKey: $idempotencyKey,
            );

            new ProductionItem(
                EntityId::generate(),
                $order,
                $variant,
                Quantity::of($payload['planned_quantity']),
            );

            if (($payload['plan'] ?? false) === true) {
                $order->markPlanned();
            }

            $this->entityManager->persist($order);

            return $this->serialize($order);
        });
    }

    /** @return array<string, mixed> */
    public function start(User $user, string $productionId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId): array {
            $order = $this->findProduction($user, $productionId);
            $this->stateMachine->assertTransition($order->getStatus(), ProductionStatus::InProgress);
            $order->transitionTo(ProductionStatus::InProgress);
            $this->ensureStageExecutions($order, $user->companyId());

            return $this->serialize($order);
        });
    }

    /** @return array<string, mixed> */
    public function pause(User $user, string $productionId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId): array {
            $order = $this->findProduction($user, $productionId);
            $this->stateMachine->assertTransition($order->getStatus(), ProductionStatus::Paused);
            $order->transitionTo(ProductionStatus::Paused);

            return $this->serialize($order);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(User $user, string $productionId, ?string $reason = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId, $reason): array {
            $order = $this->findProduction($user, $productionId);

            if (in_array($order->getStatus(), [ProductionStatus::Completed, ProductionStatus::Cancelled], true)) {
                throw new BadRequestHttpException('Production cannot be cancelled.');
            }

            $this->stateMachine->assertTransition($order->getStatus(), ProductionStatus::Cancelled);
            $order->transitionTo(ProductionStatus::Cancelled);

            return $this->serialize($order);
        });
    }

    /**
     * @param array{input_quantity?: string|null} $payload
     *
     * @return array<string, mixed>
     */
    public function startStage(User $user, string $productionId, string $stageId, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId, $stageId, $payload): array {
            $order = $this->findProduction($user, $productionId);

            if (!in_array($order->getStatus(), [ProductionStatus::InProgress, ProductionStatus::Paused], true)) {
                throw new BadRequestHttpException('Production must be in progress to start a stage.');
            }

            if ($order->getStatus() === ProductionStatus::Paused) {
                $order->transitionTo(ProductionStatus::InProgress);
            }

            $execution = $this->findStageExecution($order, $stageId);
            $inputQuantity = $this->resolveStageInput($order, $execution, $payload['input_quantity'] ?? null);
            $execution->start($inputQuantity, EntityId::fromString($user->getId()));

            return $this->serialize($order);
        });
    }

    /**
     * @param array{
     *     accepted_output_quantity: string,
     *     loss_quantity?: string,
     *     notes?: string|null,
     *     losses?: list<array{loss_reason_id?: string|null, reason_code?: string|null, quantity: string, notes?: string|null}>
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function completeStage(User $user, string $productionId, string $stageId, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId, $stageId, $payload): array {
            $order = $this->findProduction($user, $productionId);
            $execution = $this->findStageExecution($order, $stageId);
            $stage = $execution->getProductionStage();

            $accepted = Quantity::of($payload['accepted_output_quantity']);
            $loss = Quantity::of($payload['loss_quantity'] ?? '0.0000');

            $this->quantityReconciler->reconcile(
                $execution->getInputQuantity(),
                $accepted,
                $loss,
                $stage->getReconciliationMode(),
            );

            if (isset($payload['losses']) && is_array($payload['losses'])) {
                $lossTotal = Quantity::zero();
                foreach ($payload['losses'] as $lossEntry) {
                    $lossQty = Quantity::of($lossEntry['quantity']);
                    $lossTotal = $lossTotal->add($lossQty);
                    $reason = isset($lossEntry['loss_reason_id'])
                        ? $this->findLossReason($user, $lossEntry['loss_reason_id'])
                        : null;

                    $this->entityManager->persist(new ProductionLoss(
                        EntityId::generate(),
                        $execution,
                        $lossQty,
                        $reason,
                        $lossEntry['reason_code'] ?? null,
                        $lossEntry['notes'] ?? null,
                        EntityId::fromString($user->getId()),
                    ));
                }

                if (!$lossTotal->equals($loss)) {
                    throw new BadRequestHttpException('Loss line items must sum to loss_quantity.');
                }
            }

            $execution->complete($accepted, $loss, $payload['notes'] ?? null);

            if ($order->allStagesCompleted()) {
                $this->completeProduction($order, $user);
            }

            return $this->serialize($order);
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
        ?string $variantId = null,
        ?string $stageId = null,
    ): PaginatedResult {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p', 'i', 'v')
            ->from(ProductionOrder::class, 'p')
            ->join('p.items', 'i')
            ->join('i.variant', 'v')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('p.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        if ($variantId !== null) {
            $qb->andWhere('v.id = :variantId')->setParameter('variantId', $variantId);
        }

        if ($stageId !== null) {
            $qb->join('p.stageExecutions', 'se')
                ->join('se.productionStage', 'st')
                ->andWhere('st.id = :stageId')
                ->andWhere('se.status = :inProgress')
                ->setParameter('stageId', $stageId)
                ->setParameter('inProgress', StageExecutionStatus::InProgress->value);
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $paginator = new Paginator($qb, fetchJoinCollection: true);
        $items = [];

        foreach ($paginator as $order) {
            if ($order instanceof ProductionOrder) {
                $items[] = $this->serializeSummary($order);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $productionId): array
    {
        return $this->serialize($this->findProduction($user, $productionId));
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function history(User $user, string $productionId): array
    {
        $order = $this->findProduction($user, $productionId);
        $events = [];

        foreach ($order->getStageExecutions() as $execution) {
            $events[] = [
                'type' => 'stage_execution',
                'stage_id' => $execution->getProductionStage()->getId(),
                'stage_name' => $execution->getProductionStage()->getName(),
                'stage_sequence' => $execution->getStageSequence(),
                'status' => $execution->getStatus()->value,
                'input_quantity' => $execution->getInputQuantity()->amount(),
                'accepted_output_quantity' => $execution->getAcceptedOutputQuantity()->amount(),
                'loss_quantity' => $execution->getLossQuantity()->amount(),
                'started_at' => $execution->getStartedAt()?->format(DATE_ATOM),
                'completed_at' => $execution->getCompletedAt()?->format(DATE_ATOM),
                'notes' => $execution->getNotes(),
                'losses' => array_map(static fn (ProductionLoss $loss): array => [
                    'id' => $loss->getId(),
                    'reason_code' => $loss->getReasonCode(),
                    'quantity' => $loss->getQuantity()->amount(),
                    'notes' => $loss->getNotes(),
                ], $execution->getLosses()->toArray()),
            ];
        }

        return ['items' => $events];
    }

    private function completeProduction(ProductionOrder $order, User $user): void
    {
        $accepted = $order->getAcceptedOutputQuantity();
        $item = $order->getPrimaryItem();
        $item->setAcceptedOutputQuantity($accepted);

        $location = $this->availabilityService->resolveDefaultLocation($order->companyId());

        if ($location === null) {
            throw new \DomainException('No stock location configured.');
        }

        if (!$accepted->isZero()) {
            $this->stockLedgerService->postMovement(
                companyId: $order->companyId(),
                variant: $item->getVariant(),
                location: $location,
                movementType: StockMovementType::ProductionReceipt,
                quantityDelta: $accepted->amount(),
                reservedDelta: '0.0000',
                sourceType: 'production_order',
                sourceId: EntityId::fromString($order->getId()),
                reference: $order->getReference(),
                notes: 'Production completed',
                createdBy: EntityId::fromString($user->getId()),
            );

            $this->backorderAllocationService->allocateToBackorders(
                companyId: $order->companyId(),
                variant: $item->getVariant(),
                location: $location,
                actorUserId: EntityId::fromString($user->getId()),
                reference: $order->getReference(),
            );
        }

        $this->stateMachine->assertTransition($order->getStatus(), ProductionStatus::Completed);
        $order->transitionTo(ProductionStatus::Completed);
    }

    private function ensureStageExecutions(ProductionOrder $order, EntityId $companyId): void
    {
        if (!$order->getStageExecutions()->isEmpty()) {
            return;
        }

        /** @var list<ProductionStage> $stages */
        $stages = $this->entityManager->getRepository(ProductionStage::class)->findBy(
            ['companyId' => $companyId->toString(), 'isActive' => true],
            ['sequence' => 'ASC'],
        );

        if ($stages === []) {
            throw new BadRequestHttpException('No production stages configured.');
        }

        foreach ($stages as $stage) {
            $this->entityManager->persist(new StageExecution(
                EntityId::generate(),
                $order,
                $stage,
            ));
        }
    }

    private function resolveStageInput(ProductionOrder $order, StageExecution $execution, ?string $inputOverride): Quantity
    {
        if ($inputOverride !== null) {
            return Quantity::of($inputOverride);
        }

        if ($execution->getStageSequence() === 1) {
            return $order->getPrimaryItem()->getPlannedQuantity();
        }

        $previousSequence = $execution->getStageSequence() - 1;

        foreach ($order->getStageExecutions() as $stageExecution) {
            if ($stageExecution->getStageSequence() === $previousSequence) {
                if ($stageExecution->getStatus() !== StageExecutionStatus::Completed) {
                    throw new BadRequestHttpException('Previous stage must be completed first.');
                }

                return $stageExecution->getAcceptedOutputQuantity();
            }
        }

        throw new BadRequestHttpException('Unable to resolve stage input quantity.');
    }

    private function findProduction(User $user, string $productionId): ProductionOrder
    {
        /** @var ProductionOrder|null $order */
        $order = $this->entityManager->getRepository(ProductionOrder::class)->findOneBy([
            'id' => $productionId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($order === null) {
            throw new NotFoundHttpException('Production order not found.');
        }

        return $order;
    }

    private function findStageExecution(ProductionOrder $order, string $stageId): StageExecution
    {
        foreach ($order->getStageExecutions() as $execution) {
            if ($execution->getProductionStage()->getId() === $stageId) {
                return $execution;
            }
        }

        throw new NotFoundHttpException('Stage execution not found.');
    }

    private function findVariant(User $user, string $variantId): ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($variant === null) {
            throw new NotFoundHttpException('Variant not found.');
        }

        return $variant;
    }

    private function findLossReason(User $user, string $reasonId): ProductionLossReason
    {
        /** @var ProductionLossReason|null $reason */
        $reason = $this->entityManager->getRepository(ProductionLossReason::class)->findOneBy([
            'id' => $reasonId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($reason === null) {
            throw new NotFoundHttpException('Loss reason not found.');
        }

        return $reason;
    }

    private function generateReference(EntityId $companyId): string
    {
        $prefix = 'PROD-'.date('Y').'-';
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ProductionOrder::class, 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('p.reference LIKE :prefix')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%05d', $prefix, $count + 1);
    }

    /** @return array<string, mixed> */
    private function serializeSummary(ProductionOrder $order): array
    {
        $item = $order->getItems()->first();
        $currentStage = $order->getCurrentStageExecution();

        return [
            'id' => $order->getId(),
            'reference' => $order->getReference(),
            'status' => $order->getStatus()->value,
            'priority' => $order->getPriority()->value,
            'variant_id' => $item instanceof ProductionItem ? $item->getVariant()->getId() : null,
            'sku' => $item instanceof ProductionItem ? $item->getVariant()->getSku() : null,
            'planned_quantity' => $item instanceof ProductionItem ? $item->getPlannedQuantity()->amount() : null,
            'current_stage_id' => $currentStage?->getProductionStage()->getId(),
            'current_stage_name' => $currentStage?->getProductionStage()->getName(),
            'current_stage_status' => $currentStage?->getStatus()->value,
            'planned_due' => $order->getPlannedDue()?->format(DATE_ATOM),
            'created_at' => $order->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(ProductionOrder $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'variant_id' => $item->getVariant()->getId(),
                'sku' => $item->getVariant()->getSku(),
                'product_name' => $item->getVariant()->getProduct()->getName(),
                'variant_name' => $item->getVariant()->getName(),
                'planned_quantity' => $item->getPlannedQuantity()->amount(),
                'accepted_output_quantity' => $item->getAcceptedOutputQuantity()->amount(),
            ];
        }

        $stages = [];
        foreach ($order->getStageExecutions() as $execution) {
            $stages[] = [
                'id' => $execution->getProductionStage()->getId(),
                'execution_id' => $execution->getId(),
                'sequence' => $execution->getStageSequence(),
                'name' => $execution->getProductionStage()->getName(),
                'status' => $execution->getStatus()->value,
                'reconciliation_mode' => $execution->getProductionStage()->getReconciliationMode()->value,
                'can_record_quantity' => $execution->getProductionStage()->canRecordQuantity(),
                'can_record_loss' => $execution->getProductionStage()->canRecordLoss(),
                'input_quantity' => $execution->getInputQuantity()->amount(),
                'accepted_output_quantity' => $execution->getAcceptedOutputQuantity()->amount(),
                'loss_quantity' => $execution->getLossQuantity()->amount(),
                'started_at' => $execution->getStartedAt()?->format(DATE_ATOM),
                'completed_at' => $execution->getCompletedAt()?->format(DATE_ATOM),
                'notes' => $execution->getNotes(),
            ];
        }

        return [
            ...$this->serializeSummary($order),
            'source_type' => $order->getSourceType(),
            'source_id' => $order->getSourceId(),
            'planned_start' => $order->getPlannedStart()?->format(DATE_ATOM),
            'notes' => $order->getNotes(),
            'started_at' => $order->getStartedAt()?->format(DATE_ATOM),
            'completed_at' => $order->getCompletedAt()?->format(DATE_ATOM),
            'cancelled_at' => $order->getCancelledAt()?->format(DATE_ATOM),
            'items' => $items,
            'stages' => $stages,
            'can_start' => in_array($order->getStatus(), [ProductionStatus::Draft, ProductionStatus::Planned], true),
            'can_pause' => $order->getStatus() === ProductionStatus::InProgress,
            'can_cancel' => !in_array($order->getStatus(), [ProductionStatus::Completed, ProductionStatus::Cancelled], true),
        ];
    }
}
