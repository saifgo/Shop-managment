<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Audit\AuditRecorder;
use App\Application\Shared\PaginatedResult;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Inventory\StockAdjustment;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Inventory\StockMovement;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InventoryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockLedgerService $stockLedgerService,
        private AvailabilityService $availabilityService,
        private AuditRecorder $auditRecorder,
        private UnitOfWork $unitOfWork,
    ) {
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function listStock(User $user, int $page, int $perPage, ?string $variantId = null, ?string $locationId = null): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b', 'v', 'p', 'l')
            ->from(StockBalance::class, 'b')
            ->join('b.variant', 'v')
            ->join('v.product', 'p')
            ->join('b.location', 'l')
            ->where('b.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('v.sku', 'ASC');

        if ($variantId !== null) {
            $qb->andWhere('v.id = :variantId')->setParameter('variantId', $variantId);
        }

        if ($locationId !== null) {
            $qb->andWhere('l.id = :locationId')->setParameter('locationId', $locationId);
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $paginator = new Paginator($qb, fetchJoinCollection: true);
        $items = [];

        foreach ($paginator as $balance) {
            if ($balance instanceof StockBalance) {
                $availability = $this->availabilityService->forVariant(
                    $user->companyId(),
                    $balance->getVariant(),
                    $balance->getLocation(),
                );

                $items[] = [
                    'variant_id' => $balance->getVariant()->getId(),
                    'product_name' => $balance->getVariant()->getProduct()->getName(),
                    'variant_name' => $balance->getVariant()->getName(),
                    'sku' => $balance->getVariant()->getSku(),
                    'location_id' => $balance->getLocation()->getId(),
                    'location_code' => $balance->getLocation()->getCode(),
                    'location_name' => $balance->getLocation()->getName(),
                    ...$availability,
                ];
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAvailability(User $user, string $variantId, ?string $locationId = null): array
    {
        $variant = $this->findVariant($user, $variantId);
        $location = $locationId !== null
            ? $this->findLocation($user, $locationId)
            : $this->availabilityService->resolveDefaultLocation($user->companyId());

        return $this->availabilityService->forVariant($user->companyId(), $variant, $location);
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function listMovements(
        User $user,
        int $page,
        int $perPage,
        ?string $variantId = null,
        ?string $locationId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): PaginatedResult {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m', 'v', 'l')
            ->from(StockMovement::class, 'm')
            ->join('m.variant', 'v')
            ->join('m.location', 'l')
            ->where('m.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('m.createdAt', 'DESC');

        if ($variantId !== null) {
            $qb->andWhere('v.id = :variantId')->setParameter('variantId', $variantId);
        }

        if ($locationId !== null) {
            $qb->andWhere('l.id = :locationId')->setParameter('locationId', $locationId);
        }

        if ($sourceType !== null) {
            $qb->andWhere('m.sourceType = :sourceType')->setParameter('sourceType', $sourceType);
        }

        if ($sourceId !== null) {
            $qb->andWhere('m.sourceId = :sourceId')->setParameter('sourceId', $sourceId);
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $paginator = new Paginator($qb, fetchJoinCollection: true);
        $items = [];

        foreach ($paginator as $movement) {
            if ($movement instanceof StockMovement) {
                $items[] = $this->serializeMovement($movement);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    public function createAdjustment(
        User $user,
        string $variantId,
        string $locationId,
        string $quantityDelta,
        string $reason,
    ): array {
        return $this->unitOfWork->transactional(function () use ($user, $variantId, $locationId, $quantityDelta, $reason): array {
            $variant = $this->findVariant($user, $variantId);
            $location = $this->findLocation($user, $locationId);
            if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $quantityDelta)) {
                throw new BadRequestHttpException('Invalid quantity delta.');
            }

            $movement = $this->stockLedgerService->postMovement(
                companyId: $user->companyId(),
                variant: $variant,
                location: $location,
                movementType: StockMovementType::Adjustment,
                quantityDelta: $quantityDelta,
                reservedDelta: '0.0000',
                sourceType: 'stock_adjustment',
                sourceId: EntityId::generate(),
                reference: null,
                notes: $reason,
                createdBy: EntityId::fromString($user->getId()),
            );

            $adjustment = new StockAdjustment(
                EntityId::generate(),
                $user->companyId(),
                $variant,
                $location,
                $movement,
                Quantity::of(ltrim($quantityDelta, '-')),
                $reason,
                EntityId::fromString($user->getId()),
            );
            $this->entityManager->persist($adjustment);

            $this->auditRecorder->record(
                action: 'inventory.adjustment.created',
                payload: [
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'quantity_delta' => $quantityDelta,
                    'reason' => $reason,
                    'movement_id' => $movement->getId(),
                ],
                companyId: $user->companyId(),
                actorUserId: EntityId::fromString($user->getId()),
                entityType: 'stock_adjustment',
                entityId: EntityId::fromString($adjustment->getId()),
                flush: false,
            );

            return [
                'adjustment_id' => $adjustment->getId(),
                'movement' => $this->serializeMovement($movement),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function serializeMovement(StockMovement $movement): array
    {
        return [
            'id' => $movement->getId(),
            'variant_id' => $movement->getVariant()->getId(),
            'sku' => $movement->getVariant()->getSku(),
            'location_id' => $movement->getLocation()->getId(),
            'movement_type' => $movement->getMovementType()->value,
            'quantity_delta' => $movement->getQuantityDelta(),
            'reserved_delta' => $movement->getReservedDelta(),
            'source_type' => $movement->getSourceType(),
            'source_id' => $movement->getSourceId(),
            'reference' => $movement->getReference(),
            'notes' => $movement->getNotes(),
            'created_at' => $movement->getCreatedAt()->format(DATE_ATOM),
        ];
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

    private function findLocation(User $user, string $locationId): \App\Infrastructure\Persistence\Entity\Inventory\StockLocation
    {
        /** @var \App\Infrastructure\Persistence\Entity\Inventory\StockLocation|null $location */
        $location = $this->entityManager->getRepository(\App\Infrastructure\Persistence\Entity\Inventory\StockLocation::class)->findOneBy([
            'id' => $locationId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($location === null) {
            throw new NotFoundHttpException('Stock location not found.');
        }

        return $location;
    }
}
