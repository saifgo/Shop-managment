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
use App\Infrastructure\Persistence\Entity\Production\StageExecutionLine;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A production order makes one or more products (production items). All products move through
 * the configured stages together; each stage records input / accepted output / loss per product.
 */
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
     *     items?: list<array{variant_id?: string|null, planned_quantity?: string|null}>|null,
     *     variant_id?: string|null,
     *     planned_quantity?: string|null,
     *     priority?: string|null,
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

            $lines = $this->resolveCreateLines($user, $payload);
            $reference = $this->generateReference($user->companyId());
            $priority = ProductionPriority::tryFrom(strtoupper($payload['priority'] ?? 'NORMAL')) ?? ProductionPriority::Normal;

            $order = new ProductionOrder(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                reference: $reference,
                priority: $priority,
                sourceType: $payload['source_type'] ?? null,
                sourceId: isset($payload['source_id']) && $payload['source_id'] !== '' ? EntityId::fromString($payload['source_id']) : null,
                plannedStart: $this->parseOptionalDate($payload['planned_start'] ?? null, 'planned_start'),
                plannedDue: $this->parseOptionalDate($payload['planned_due'] ?? null, 'planned_due'),
                notes: ($payload['notes'] ?? null) !== '' ? ($payload['notes'] ?? null) : null,
                createdBy: EntityId::fromString($user->getId()),
                idempotencyKey: $idempotencyKey,
            );

            foreach ($lines as $line) {
                new ProductionItem(EntityId::generate(), $order, $line['variant'], $line['quantity']);
            }

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
     * Inputs default to the planned quantity (first stage) or the previous stage's accepted
     * output, per product. `items` overrides individual products; `input_quantity` is the
     * legacy single-product override.
     *
     * @param array{
     *     input_quantity?: string|null,
     *     items?: list<array{item_id?: string|null, input_quantity?: string|null}>|null
     * } $payload
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

            if ($execution->getStatus() !== StageExecutionStatus::Pending) {
                throw new BadRequestHttpException('Only pending stages can be started.');
            }

            $overrides = $this->resolveInputOverrides($order, $payload);
            $inputs = [];
            $total = Quantity::zero();

            foreach ($order->getItems() as $item) {
                $quantity = $overrides[$item->getId()] ?? $this->defaultStageInput($order, $execution, $item);
                $inputs[] = ['item' => $item, 'quantity' => $quantity];
                $total = $total->add($quantity);
            }

            if ($total->isZero()) {
                throw new BadRequestHttpException('Nothing left to process at this stage.');
            }

            $execution->start($inputs, EntityId::fromString($user->getId()));

            return $this->serialize($order);
        });
    }

    /**
     * @param array{
     *     items?: list<array{
     *         item_id?: string|null,
     *         accepted_output_quantity?: string|null,
     *         loss_quantity?: string|null,
     *         losses?: list<array{loss_reason_id?: string|null, reason_code?: string|null, quantity: string, notes?: string|null}>|null
     *     }>|null,
     *     accepted_output_quantity?: string|null,
     *     loss_quantity?: string|null,
     *     notes?: string|null,
     *     losses?: list<array{loss_reason_id?: string|null, reason_code?: string|null, quantity: string, notes?: string|null}>|null
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function completeStage(User $user, string $productionId, string $stageId, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId, $stageId, $payload): array {
            $order = $this->findProduction($user, $productionId);
            $execution = $this->findStageExecution($order, $stageId);

            if ($execution->getStatus() !== StageExecutionStatus::InProgress) {
                throw new BadRequestHttpException('Only in-progress stages can be completed.');
            }

            $mode = $execution->getProductionStage()->getReconciliationMode();
            $results = $this->resolveCompletionResults($execution, $payload);

            foreach ($execution->getLines() as $line) {
                $item = $line->getProductionItem();
                $result = $results[$item->getId()];
                $accepted = $this->parseQuantity($result['accepted_output_quantity'] ?? null, 'accepted_output_quantity');
                $loss = $this->parseQuantity($result['loss_quantity'] ?? null, 'loss_quantity', allowEmpty: true);
                $label = $item->getVariant()->getSku();

                if ($line->getInputQuantity()->isZero()) {
                    if (!$accepted->isZero() || !$loss->isZero()) {
                        throw new BadRequestHttpException(sprintf('%s: nothing entered this stage, so accepted output and loss must be 0.', $label));
                    }
                } else {
                    try {
                        $this->quantityReconciler->reconcile($line->getInputQuantity(), $accepted, $loss, $mode);
                    } catch (\DomainException $exception) {
                        throw new BadRequestHttpException(sprintf('%s: %s', $label, $exception->getMessage()), $exception);
                    }
                }

                $this->recordLosses($user, $execution, $item, $loss, $result['losses'] ?? null);
                $line->record($accepted, $loss);
            }

            $execution->complete($payload['notes'] ?? null);

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
            // No DISTINCT: product_variants.attributes is a JSON column and PostgreSQL cannot
            // compare json values. The fetch-join Paginator already de-duplicates root rows.
            ->select('p', 'i', 'v', 'pr')
            ->from(ProductionOrder::class, 'p')
            ->join('p.items', 'i')
            ->join('i.variant', 'v')
            ->join('v.product', 'pr')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('p.createdAt', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        if ($variantId !== null && $variantId !== '') {
            // Filter through a subquery so the fetch-joined items collection stays complete.
            $qb->andWhere(sprintf(
                'EXISTS (SELECT fi.id FROM %s fi WHERE fi.productionOrder = p AND IDENTITY(fi.variant) = :variantId)',
                ProductionItem::class,
            ))->setParameter('variantId', $variantId);
        }

        if ($stageId !== null && $stageId !== '') {
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
                'lines' => $this->serializeLines($execution),
                'losses' => array_map(static fn (ProductionLoss $loss): array => [
                    'id' => $loss->getId(),
                    'item_id' => $loss->getProductionItem()?->getId(),
                    'reason_code' => $loss->getReasonCode(),
                    'quantity' => $loss->getQuantity()->amount(),
                    'notes' => $loss->getNotes(),
                ], $execution->getLosses()->toArray()),
            ];
        }

        return ['items' => $events];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{variant: ProductVariant, quantity: Quantity}>
     */
    private function resolveCreateLines(User $user, array $payload): array
    {
        $rawItems = $payload['items'] ?? null;

        if ($rawItems === null || $rawItems === []) {
            if (($payload['variant_id'] ?? null) === null || ($payload['variant_id'] ?? '') === '') {
                throw new BadRequestHttpException('Add at least one product to produce.');
            }

            $rawItems = [['variant_id' => $payload['variant_id'], 'planned_quantity' => $payload['planned_quantity'] ?? null]];
        }

        $lines = [];
        $seen = [];

        foreach (array_values($rawItems) as $index => $rawItem) {
            $variantId = is_array($rawItem) ? ($rawItem['variant_id'] ?? null) : null;

            if (!is_string($variantId) || $variantId === '') {
                throw new BadRequestHttpException(sprintf('items[%d].variant_id is required.', $index));
            }

            if (isset($seen[$variantId])) {
                throw new BadRequestHttpException('Each product can only appear once in a production order.');
            }
            $seen[$variantId] = true;

            $lines[] = [
                'variant' => $this->findVariant($user, $variantId),
                'quantity' => $this->parsePositiveQuantity((string) ($rawItem['planned_quantity'] ?? ''), sprintf('items[%d].planned_quantity', $index)),
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, Quantity> keyed by production item id
     */
    private function resolveInputOverrides(ProductionOrder $order, array $payload): array
    {
        $overrides = [];

        foreach ($payload['items'] ?? [] as $index => $entry) {
            $itemId = is_array($entry) ? ($entry['item_id'] ?? null) : null;
            $quantity = is_array($entry) ? ($entry['input_quantity'] ?? null) : null;

            if ($quantity === null || $quantity === '') {
                continue;
            }

            $item = is_string($itemId) ? $this->findItem($order, $itemId) : null;
            if ($item === null) {
                throw new BadRequestHttpException(sprintf('items[%d].item_id is not part of this production order.', $index));
            }

            $overrides[$item->getId()] = $this->parseQuantity((string) $quantity, sprintf('items[%d].input_quantity', $index));
        }

        $legacy = $payload['input_quantity'] ?? null;
        if ($overrides === [] && $legacy !== null && $legacy !== '') {
            if ($order->getItems()->count() !== 1) {
                throw new BadRequestHttpException('This order has several products: pass input quantities per product in items[].');
            }

            $overrides[$order->getPrimaryItem()->getId()] = $this->parseQuantity((string) $legacy, 'input_quantity');
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, array<string, mixed>> keyed by production item id; one entry per stage line
     */
    private function resolveCompletionResults(StageExecution $execution, array $payload): array
    {
        $results = [];
        $order = $execution->getProductionOrder();
        $rawItems = $payload['items'] ?? null;

        if ($rawItems === null || $rawItems === []) {
            if ($execution->getLines()->count() !== 1) {
                throw new BadRequestHttpException('This order has several products: record results per product in items[].');
            }

            $line = $execution->getLines()->first();
            \assert($line instanceof StageExecutionLine);
            $results[$line->getProductionItem()->getId()] = [
                'accepted_output_quantity' => $payload['accepted_output_quantity'] ?? null,
                'loss_quantity' => $payload['loss_quantity'] ?? null,
                'losses' => $payload['losses'] ?? null,
            ];
        } else {
            foreach ($rawItems as $index => $entry) {
                $itemId = is_array($entry) ? ($entry['item_id'] ?? null) : null;
                $item = is_string($itemId) ? $this->findItem($order, $itemId) : null;

                if ($item === null) {
                    throw new BadRequestHttpException(sprintf('items[%d].item_id is not part of this production order.', $index));
                }

                if (isset($results[$item->getId()])) {
                    throw new BadRequestHttpException('Each product can only be reported once per stage.');
                }

                $results[$item->getId()] = $entry;
            }
        }

        foreach ($execution->getLines() as $line) {
            if (!isset($results[$line->getProductionItem()->getId()])) {
                throw new BadRequestHttpException(sprintf(
                    'Missing results for %s.',
                    $line->getProductionItem()->getVariant()->getSku(),
                ));
            }
        }

        return $results;
    }

    /**
     * @param list<array{loss_reason_id?: string|null, reason_code?: string|null, quantity: string, notes?: string|null}>|null $losses
     */
    private function recordLosses(User $user, StageExecution $execution, ProductionItem $item, Quantity $loss, ?array $losses): void
    {
        if ($losses === null || $losses === []) {
            return;
        }

        $lossTotal = Quantity::zero();
        foreach ($losses as $lossEntry) {
            $lossQty = $this->parseQuantity((string) ($lossEntry['quantity'] ?? ''), 'losses.quantity');
            $lossTotal = $lossTotal->add($lossQty);
            $reason = isset($lossEntry['loss_reason_id']) && $lossEntry['loss_reason_id'] !== ''
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
                $item,
            ));
        }

        if (!$lossTotal->equals($loss)) {
            throw new BadRequestHttpException(sprintf(
                '%s: loss line items must sum to loss_quantity.',
                $item->getVariant()->getSku(),
            ));
        }
    }

    private function completeProduction(ProductionOrder $order, User $user): void
    {
        $location = $this->availabilityService->resolveDefaultLocation($order->companyId());

        if ($location === null) {
            throw new \DomainException('No stock location configured.');
        }

        $lastStage = $order->getLastCompletedStageExecution();

        foreach ($order->getItems() as $item) {
            $accepted = $lastStage?->getLineForItem($item)?->getAcceptedOutputQuantity() ?? Quantity::zero();
            $item->setAcceptedOutputQuantity($accepted);

            if ($accepted->isZero()) {
                continue;
            }

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

    private function defaultStageInput(ProductionOrder $order, StageExecution $execution, ProductionItem $item): Quantity
    {
        $previous = $this->previousStageExecution($order, $execution);

        if ($previous === null) {
            return $item->getPlannedQuantity();
        }

        if ($previous->getStatus() !== StageExecutionStatus::Completed) {
            throw new BadRequestHttpException('Previous stage must be completed first.');
        }

        $line = $previous->getLineForItem($item);

        if ($line !== null) {
            return $line->getAcceptedOutputQuantity();
        }

        // Stage completed before per-product lines existed: its totals belong to the single product.
        return $order->getItems()->count() === 1 ? $previous->getAcceptedOutputQuantity() : Quantity::zero();
    }

    private function previousStageExecution(ProductionOrder $order, StageExecution $execution): ?StageExecution
    {
        $previous = null;

        foreach ($order->getStageExecutions() as $stageExecution) {
            if ($stageExecution->getStageSequence() >= $execution->getStageSequence()) {
                continue;
            }

            if ($previous === null || $stageExecution->getStageSequence() > $previous->getStageSequence()) {
                $previous = $stageExecution;
            }
        }

        return $previous;
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

    private function findItem(ProductionOrder $order, string $itemId): ?ProductionItem
    {
        foreach ($order->getItems() as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        return null;
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

    private function parseQuantity(?string $amount, string $field, bool $allowEmpty = false): Quantity
    {
        if ($amount === null || trim($amount) === '') {
            if ($allowEmpty) {
                return Quantity::zero();
            }

            throw new BadRequestHttpException(sprintf('%s is required.', $field));
        }

        try {
            return Quantity::of(trim($amount));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('%s must be a number of 0 or more with up to 4 decimals.', $field));
        }
    }

    private function parsePositiveQuantity(string $amount, string $field): Quantity
    {
        try {
            $quantity = Quantity::of(trim($amount));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('%s must be a positive number with up to 4 decimals.', $field));
        }

        if ($quantity->isZero()) {
            throw new BadRequestHttpException(sprintf('%s must be greater than zero.', $field));
        }

        return $quantity;
    }

    private function parseOptionalDate(?string $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s is not a valid date.', $field));
        }
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
        $first = $order->getItems()->first();
        $currentStage = $order->getCurrentStageExecution();
        $plannedTotal = Quantity::zero();
        $products = [];

        foreach ($order->getItems() as $item) {
            $plannedTotal = $plannedTotal->add($item->getPlannedQuantity());
            $products[] = [
                'item_id' => $item->getId(),
                'variant_id' => $item->getVariant()->getId(),
                'sku' => $item->getVariant()->getSku(),
                'product_name' => $item->getVariant()->getProduct()->getName(),
                'variant_name' => $item->getVariant()->getName(),
                'planned_quantity' => $item->getPlannedQuantity()->amount(),
            ];
        }

        return [
            'id' => $order->getId(),
            'reference' => $order->getReference(),
            'status' => $order->getStatus()->value,
            'priority' => $order->getPriority()->value,
            // First product, kept for single-product clients; see `products` for the full list.
            'variant_id' => $first instanceof ProductionItem ? $first->getVariant()->getId() : null,
            'sku' => $first instanceof ProductionItem ? $first->getVariant()->getSku() : null,
            'item_count' => count($products),
            'products' => $products,
            'planned_quantity' => $plannedTotal->amount(),
            'current_stage_id' => $currentStage?->getProductionStage()->getId(),
            'current_stage_name' => $currentStage?->getProductionStage()->getName(),
            'current_stage_status' => $currentStage?->getStatus()->value,
            'planned_due' => $order->getPlannedDue()?->format(DATE_ATOM),
            'created_at' => $order->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function serializeLines(StageExecution $execution): array
    {
        $lines = [];

        foreach ($execution->getLines() as $line) {
            $variant = $line->getProductionItem()->getVariant();
            $lines[] = [
                'id' => $line->getId(),
                'item_id' => $line->getProductionItem()->getId(),
                'variant_id' => $variant->getId(),
                'sku' => $variant->getSku(),
                'product_name' => $variant->getProduct()->getName(),
                'variant_name' => $variant->getName(),
                'input_quantity' => $line->getInputQuantity()->amount(),
                'accepted_output_quantity' => $line->getAcceptedOutputQuantity()->amount(),
                'loss_quantity' => $line->getLossQuantity()->amount(),
            ];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function serialize(ProductionOrder $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $lost = Quantity::zero();
            foreach ($order->getStageExecutions() as $execution) {
                $line = $execution->getLineForItem($item);
                if ($line !== null) {
                    $lost = $lost->add($line->getLossQuantity());
                }
            }

            $items[] = [
                'id' => $item->getId(),
                'variant_id' => $item->getVariant()->getId(),
                'sku' => $item->getVariant()->getSku(),
                'product_name' => $item->getVariant()->getProduct()->getName(),
                'variant_name' => $item->getVariant()->getName(),
                'planned_quantity' => $item->getPlannedQuantity()->amount(),
                'accepted_output_quantity' => $item->getAcceptedOutputQuantity()->amount(),
                'loss_quantity' => $lost->amount(),
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
                'lines' => $this->serializeLines($execution),
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
