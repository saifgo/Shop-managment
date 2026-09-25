<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Audit\AuditRecorder;
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
use App\Infrastructure\Persistence\Entity\Production\ProductionOrder;
use App\Infrastructure\Persistence\Entity\Production\StageExecution;
use App\Infrastructure\Persistence\Entity\Production\StageExecutionLine;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Production workflow (blueprint §8, §19.2).
 *
 * A production order makes one or more products (production items). Lifecycle:
 * DRAFT -> PLANNED -> IN_PROGRESS <-> PAUSED -> COMPLETED, or CANCELLED before completion.
 *
 * Starting an order creates one stage execution per active configured stage. All products move
 * through the stages together and strictly in sequence; each stage records input, accepted output
 * and loss per product, and every loss carries a configured reason. When the last stage completes,
 * each product's accepted output is received into stock and allocated to waiting backorders.
 *
 * Every command runs in one transaction with the order row locked, and leaves an audit event.
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
        private ProductionConfigService $configService,
        private AuditRecorder $auditRecorder,
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
            $this->audit($user, $order, 'production.created', [
                'status' => $order->getStatus()->value,
                'items' => array_map(static fn (array $line): array => [
                    'variant_id' => $line['variant']->getId(),
                    'sku' => $line['variant']->getSku(),
                    'planned_quantity' => $line['quantity']->amount(),
                ], $lines),
            ]);

            return $this->serialize($order);
        });
    }

    /** DRAFT -> PLANNED: commits the quantities to manufacturing (they count as "already in production"). */
    public function plan(User $user, string $productionId): array
    {
        return $this->transition($user, $productionId, ProductionStatus::Planned, 'production.planned');
    }

    /** DRAFT/PLANNED -> IN_PROGRESS; creates one stage execution per active configured stage. A paused order resumes. */
    public function start(User $user, string $productionId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId): array {
            $order = $this->findProduction($user, $productionId, lock: true);

            if ($order->getStatus() === ProductionStatus::Paused) {
                return $this->doTransition($user, $order, ProductionStatus::InProgress, 'production.resumed');
            }

            $this->stateMachine->assertTransition($order->getStatus(), ProductionStatus::InProgress);
            $this->ensureStageExecutions($order);
            $order->transitionTo(ProductionStatus::InProgress);
            $this->audit($user, $order, 'production.started', [
                'stages' => array_map(
                    static fn (StageExecution $execution): string => $execution->getProductionStage()->getName(),
                    $order->getStageExecutions()->toArray(),
                ),
            ]);

            return $this->serialize($order);
        });
    }

    /** PAUSED -> IN_PROGRESS. */
    public function resume(User $user, string $productionId): array
    {
        return $this->transition($user, $productionId, ProductionStatus::InProgress, 'production.resumed', requireFrom: ProductionStatus::Paused);
    }

    /** IN_PROGRESS -> PAUSED: no stage can be started or completed until the order is resumed. */
    public function pause(User $user, string $productionId): array
    {
        return $this->transition($user, $productionId, ProductionStatus::Paused, 'production.paused');
    }

    /** @return array<string, mixed> */
    public function cancel(User $user, string $productionId, ?string $reason = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $productionId, $reason): array {
            $order = $this->findProduction($user, $productionId, lock: true);

            if (in_array($order->getStatus(), [ProductionStatus::Completed, ProductionStatus::Cancelled], true)) {
                throw new BadRequestHttpException('Production cannot be cancelled.');
            }

            $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

            return $this->doTransition($user, $order, ProductionStatus::Cancelled, 'production.cancelled', [
                'reason' => $reason,
                'in_process' => $this->inProcessSnapshot($order),
            ]);
        });
    }

    /**
     * Starts the next stage. Inputs default to the planned quantity (first stage) or the previous
     * stage's accepted output, per product. `items` overrides individual products; `input_quantity`
     * is the single-product override.
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
            $order = $this->findProduction($user, $productionId, lock: true);
            $this->assertWorkable($order);
            $execution = $this->findStageExecution($order, $stageId);

            if ($execution->getStatus() !== StageExecutionStatus::Pending) {
                throw new BadRequestHttpException(sprintf('%s has already been started.', $execution->getProductionStage()->getName()));
            }

            foreach ($order->getStageExecutions() as $other) {
                if ($other->getStatus() === StageExecutionStatus::InProgress) {
                    throw new BadRequestHttpException(sprintf('Complete %s before starting the next stage.', $other->getProductionStage()->getName()));
                }
            }

            $previous = $this->previousStageExecution($order, $execution);
            if ($previous !== null && $previous->getStatus() !== StageExecutionStatus::Completed) {
                throw new BadRequestHttpException(sprintf('Complete %s first: stages run in order.', $previous->getProductionStage()->getName()));
            }

            $overrides = $this->resolveInputOverrides($order, $payload);
            $inputs = [];
            $total = Quantity::zero();

            foreach ($order->getItems() as $item) {
                $available = $this->defaultStageInput($order, $previous, $item);
                $quantity = $overrides[$item->getId()] ?? $available;

                // Later stages can only work with what the previous stage accepted.
                if ($previous !== null && $quantity->isGreaterThan($available)) {
                    throw new BadRequestHttpException(sprintf(
                        '%s: only %s came out of %s.',
                        $item->getVariant()->getSku(),
                        $available->amount(),
                        $previous->getProductionStage()->getName(),
                    ));
                }

                $inputs[] = ['item' => $item, 'quantity' => $quantity];
                $total = $total->add($quantity);
            }

            if ($total->isZero()) {
                throw new BadRequestHttpException('Nothing left to process at this stage.');
            }

            $execution->start($inputs, EntityId::fromString($user->getId()));
            $this->audit($user, $order, 'production.stage.started', [
                'stage' => $execution->getProductionStage()->getName(),
                'inputs' => array_map(static fn (array $input): array => [
                    'sku' => $input['item']->getVariant()->getSku(),
                    'input_quantity' => $input['quantity']->amount(),
                ], $inputs),
            ]);

            return $this->serialize($order);
        });
    }

    /**
     * Records the outcome of the running stage for every product and closes it. Quantities must
     * reconcile per the stage's mode, and every loss needs a configured reason. Completing the last
     * stage completes the production and receives the output into stock.
     *
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
            $order = $this->findProduction($user, $productionId, lock: true);
            $this->assertWorkable($order);
            $execution = $this->findStageExecution($order, $stageId);
            $stage = $execution->getProductionStage();

            if ($execution->getStatus() !== StageExecutionStatus::InProgress) {
                throw new BadRequestHttpException(sprintf('%s is not running.', $stage->getName()));
            }

            // Stages that do not record quantities pass everything through unchanged.
            $results = $stage->canRecordQuantity() ? $this->resolveCompletionResults($execution, $payload) : null;
            $recorded = [];

            foreach ($execution->getLines() as $line) {
                $item = $line->getProductionItem();
                $sku = $item->getVariant()->getSku();

                if ($results === null) {
                    $line->record($line->getInputQuantity(), Quantity::zero());
                    continue;
                }

                $result = $results[$item->getId()];
                $accepted = $this->parseQuantity($result['accepted_output_quantity'] ?? null, 'accepted_output_quantity');
                $loss = $this->parseQuantity($result['loss_quantity'] ?? null, 'loss_quantity', allowEmpty: true);

                if (!$loss->isZero() && !$stage->canRecordLoss()) {
                    throw new BadRequestHttpException(sprintf('%s does not record losses.', $stage->getName()));
                }

                if ($line->getInputQuantity()->isZero()) {
                    if (!$accepted->isZero() || !$loss->isZero()) {
                        throw new BadRequestHttpException(sprintf('%s: nothing entered this stage, so accepted output and loss must be 0.', $sku));
                    }
                } else {
                    try {
                        $this->quantityReconciler->reconcile($line->getInputQuantity(), $accepted, $loss, $stage->getReconciliationMode());
                    } catch (\DomainException $exception) {
                        throw new BadRequestHttpException(sprintf('%s: %s', $sku, $exception->getMessage()), $exception);
                    }
                }

                $losses = $this->recordLosses($user, $execution, $item, $loss, $result['losses'] ?? null);
                $line->record($accepted, $loss);
                $recorded[] = [
                    'sku' => $sku,
                    'input_quantity' => $line->getInputQuantity()->amount(),
                    'accepted_output_quantity' => $accepted->amount(),
                    'loss_quantity' => $loss->amount(),
                    'losses' => $losses,
                ];
            }

            $notes = isset($payload['notes']) && trim($payload['notes']) !== '' ? trim($payload['notes']) : null;
            $execution->complete($notes);
            $this->audit($user, $order, 'production.stage.completed', [
                'stage' => $stage->getName(),
                'pass_through' => $results === null,
                'lines' => $recorded,
                'notes' => $notes,
            ]);

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
                ...$this->serializeStageExecution($execution),
                'losses' => array_map($this->serializeLoss(...), $execution->getLosses()->toArray()),
            ];
        }

        return ['items' => $events];
    }

    /**
     * @return array<string, mixed>
     */
    private function transition(
        User $user,
        string $productionId,
        ProductionStatus $to,
        string $action,
        ?ProductionStatus $requireFrom = null,
    ): array {
        return $this->unitOfWork->transactional(function () use ($user, $productionId, $to, $action, $requireFrom): array {
            $order = $this->findProduction($user, $productionId, lock: true);

            if ($requireFrom !== null && $order->getStatus() !== $requireFrom) {
                throw new BadRequestHttpException(sprintf('Only %s productions can do this.', strtolower($requireFrom->value)));
            }

            return $this->doTransition($user, $order, $to, $action);
        });
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function doTransition(User $user, ProductionOrder $order, ProductionStatus $to, string $action, array $details = []): array
    {
        $from = $order->getStatus();

        try {
            $this->stateMachine->assertTransition($from, $to);
        } catch (\DomainException) {
            throw new BadRequestHttpException(sprintf(
                'A %s production cannot become %s.',
                strtolower(str_replace('_', ' ', $from->value)),
                strtolower(str_replace('_', ' ', $to->value)),
            ));
        }

        $order->transitionTo($to);
        $this->audit($user, $order, $action, ['from' => $from->value, 'to' => $to->value, ...$details]);

        return $this->serialize($order);
    }

    private function assertWorkable(ProductionOrder $order): void
    {
        match ($order->getStatus()) {
            ProductionStatus::InProgress => null,
            ProductionStatus::Paused => throw new BadRequestHttpException('Production is paused. Resume it before working on stages.'),
            ProductionStatus::Draft, ProductionStatus::Planned => throw new BadRequestHttpException('Start the production before working on stages.'),
            default => throw new BadRequestHttpException(sprintf('Production is %s.', strtolower($order->getStatus()->value))),
        };
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
     * Every lost unit must be explained by a configured loss reason (blueprint §8.3, §30).
     *
     * @param list<array{loss_reason_id?: string|null, reason_code?: string|null, quantity: string, notes?: string|null}>|null $losses
     *
     * @return list<array{reason_code: string, quantity: string}>
     */
    private function recordLosses(User $user, StageExecution $execution, ProductionItem $item, Quantity $loss, ?array $losses): array
    {
        $sku = $item->getVariant()->getSku();
        $losses ??= [];

        if ($loss->isZero() && $losses === []) {
            return [];
        }

        if ($losses === []) {
            throw new BadRequestHttpException(sprintf('%s: choose a reason for the %s lost.', $sku, $loss->amount()));
        }

        $lossTotal = Quantity::zero();
        $recorded = [];
        foreach ($losses as $lossEntry) {
            $lossQty = $this->parsePositiveQuantity((string) ($lossEntry['quantity'] ?? ''), $sku.' loss quantity');
            $lossTotal = $lossTotal->add($lossQty);
            $reason = $this->configService->requireActiveLossReason(
                $user->companyId(),
                $lossEntry['loss_reason_id'] ?? null,
                $lossEntry['reason_code'] ?? null,
            );
            $notes = isset($lossEntry['notes']) && trim((string) $lossEntry['notes']) !== '' ? trim((string) $lossEntry['notes']) : null;

            $this->entityManager->persist(new ProductionLoss(
                EntityId::generate(),
                $execution,
                $lossQty,
                $reason,
                $reason->getCode(),
                $notes,
                EntityId::fromString($user->getId()),
                $item,
            ));
            $recorded[] = ['reason_code' => $reason->getCode(), 'quantity' => $lossQty->amount()];
        }

        if (!$lossTotal->equals($loss)) {
            throw new BadRequestHttpException(sprintf(
                '%s: the loss reasons add up to %s but the loss is %s.',
                $sku,
                $lossTotal->amount(),
                $loss->amount(),
            ));
        }

        return $recorded;
    }

    private function completeProduction(ProductionOrder $order, User $user): void
    {
        $location = $this->availabilityService->requireDefaultLocation($order->companyId());
        $lastStage = $order->getLastCompletedStageExecution();
        $receipts = [];

        foreach ($order->getItems() as $item) {
            $accepted = $lastStage?->getLineForItem($item)?->getAcceptedOutputQuantity() ?? Quantity::zero();
            $item->setAcceptedOutputQuantity($accepted);
            $receipts[] = [
                'sku' => $item->getVariant()->getSku(),
                'planned_quantity' => $item->getPlannedQuantity()->amount(),
                'accepted_output_quantity' => $accepted->amount(),
            ];

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

        $this->doTransition($user, $order, ProductionStatus::Completed, 'production.completed', [
            'location' => $location->getCode(),
            'receipts' => $receipts,
        ]);
    }

    private function ensureStageExecutions(ProductionOrder $order): void
    {
        if (!$order->getStageExecutions()->isEmpty()) {
            return;
        }

        $stages = $this->configService->activeStages($order->companyId());

        if ($stages === []) {
            throw new BadRequestHttpException('Every production stage is deactivated. Activate at least one in the production workflow settings.');
        }

        foreach ($stages as $stage) {
            $this->entityManager->persist(new StageExecution(
                EntityId::generate(),
                $order,
                $stage,
            ));
        }
    }

    private function defaultStageInput(ProductionOrder $order, ?StageExecution $previous, ProductionItem $item): Quantity
    {
        if ($previous === null) {
            return $item->getPlannedQuantity();
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

    /**
     * @param bool $lock take a row lock for the rest of the transaction, so two people acting on
     *                   the same order are serialized
     */
    private function findProduction(User $user, string $productionId, bool $lock = false): ProductionOrder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(ProductionOrder::class, 'p')
            ->where('p.id = :id')
            ->andWhere('p.companyId = :companyId')
            ->setParameter('id', $productionId)
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery();

        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        /** @var ProductionOrder|null $order */
        $order = $query->getOneOrNullResult();

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

    /** @param array<string, mixed> $payload */
    private function audit(User $user, ProductionOrder $order, string $action, array $payload): void
    {
        $this->auditRecorder->record(
            action: $action,
            payload: ['reference' => $order->getReference(), ...$payload],
            companyId: $user->companyId(),
            actorUserId: EntityId::fromString($user->getId()),
            entityType: 'production_order',
            entityId: EntityId::fromString($order->getId()),
            flush: false,
        );
    }

    /** @return list<array{sku: string, quantity: string}> */
    private function inProcessSnapshot(ProductionOrder $order): array
    {
        $snapshot = [];
        foreach ($order->getItems() as $item) {
            $snapshot[] = ['sku' => $item->getVariant()->getSku(), 'quantity' => $order->currentQuantityFor($item)->amount()];
        }

        return $snapshot;
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

    /** @return array<string, mixed> */
    private function serializeLoss(ProductionLoss $loss): array
    {
        return [
            'id' => $loss->getId(),
            'item_id' => $loss->getProductionItem()?->getId(),
            'reason_code' => $loss->getReasonCode(),
            'reason_label' => $loss->getLossReason()?->getLabel() ?? $loss->getReasonCode(),
            'quantity' => $loss->getQuantity()->amount(),
            'notes' => $loss->getNotes(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeStageExecution(StageExecution $execution): array
    {
        $stage = $execution->getProductionStage();
        $lossesByItem = [];
        foreach ($execution->getLosses() as $loss) {
            $lossesByItem[$loss->getProductionItem()?->getId() ?? ''][] = $this->serializeLoss($loss);
        }

        $lines = [];
        foreach ($execution->getLines() as $line) {
            $item = $line->getProductionItem();
            $variant = $item->getVariant();
            $lines[] = [
                'id' => $line->getId(),
                'item_id' => $item->getId(),
                'variant_id' => $variant->getId(),
                'sku' => $variant->getSku(),
                'product_name' => $variant->getProduct()->getName(),
                'variant_name' => $variant->getName(),
                'input_quantity' => $line->getInputQuantity()->amount(),
                'accepted_output_quantity' => $line->getAcceptedOutputQuantity()->amount(),
                'loss_quantity' => $line->getLossQuantity()->amount(),
                'losses' => $lossesByItem[$item->getId()] ?? [],
            ];
        }

        return [
            'id' => $stage->getId(),
            'execution_id' => $execution->getId(),
            'sequence' => $execution->getStageSequence(),
            'name' => $stage->getName(),
            'status' => $execution->getStatus()->value,
            'reconciliation_mode' => $stage->getReconciliationMode()->value,
            'can_record_quantity' => $stage->canRecordQuantity(),
            'can_record_loss' => $stage->canRecordLoss(),
            'input_quantity' => $execution->getInputQuantity()->amount(),
            'accepted_output_quantity' => $execution->getAcceptedOutputQuantity()->amount(),
            'loss_quantity' => $execution->getLossQuantity()->amount(),
            'performed_by' => $execution->getPerformedBy(),
            'performed_by_name' => $this->userName($execution->getPerformedBy()),
            'started_at' => $execution->getStartedAt()?->format(DATE_ATOM),
            'completed_at' => $execution->getCompletedAt()?->format(DATE_ATOM),
            'notes' => $execution->getNotes(),
            'lines' => $lines,
        ];
    }

    /** @var array<string, string|null> */
    private array $userNames = [];

    private function userName(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        if (!array_key_exists($userId, $this->userNames)) {
            $user = $this->entityManager->find(User::class, $userId);
            $this->userNames[$userId] = $user instanceof User
                ? trim($user->getFirstName().' '.$user->getLastName())
                : null;
        }

        return $this->userNames[$userId];
    }

    /** @return array<string, mixed> */
    private function serialize(ProductionOrder $order): array
    {
        $status = $order->getStatus();
        $active = in_array($status, [ProductionStatus::Planned, ProductionStatus::InProgress, ProductionStatus::Paused], true);
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
                'in_process_quantity' => $active ? $order->currentQuantityFor($item)->amount() : '0.0000',
                'loss_quantity' => $lost->amount(),
                'accepted_output_quantity' => $item->getAcceptedOutputQuantity()->amount(),
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
            'stages' => array_map($this->serializeStageExecution(...), $order->getStageExecutions()->toArray()),
            'can_plan' => $status === ProductionStatus::Draft,
            'can_start' => in_array($status, [ProductionStatus::Draft, ProductionStatus::Planned], true),
            'can_pause' => $status === ProductionStatus::InProgress,
            'can_resume' => $status === ProductionStatus::Paused,
            'can_cancel' => !in_array($status, [ProductionStatus::Completed, ProductionStatus::Cancelled], true),
        ];
    }
}
