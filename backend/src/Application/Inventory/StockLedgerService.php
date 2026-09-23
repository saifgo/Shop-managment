<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\StockMovementType;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use App\Infrastructure\Persistence\Entity\Inventory\StockMovement;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class StockLedgerService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function postMovement(
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
        StockMovementType $movementType,
        string $quantityDelta,
        string $reservedDelta,
        string $sourceType,
        EntityId $sourceId,
        ?string $reference = null,
        ?string $notes = null,
        ?EntityId $createdBy = null,
    ): StockMovement {
        $balance = $this->lockOrCreateBalance($companyId, $variant, $location);

        $movement = new StockMovement(
            id: EntityId::generate(),
            companyId: $companyId,
            variant: $variant,
            location: $location,
            movementType: $movementType,
            quantityDelta: $quantityDelta,
            reservedDelta: $reservedDelta,
            sourceType: $sourceType,
            sourceId: $sourceId,
            reference: $reference,
            notes: $notes,
            createdBy: $createdBy,
        );

        $balance->applyMovement($quantityDelta, $reservedDelta);
        $this->entityManager->persist($movement);

        return $movement;
    }

    public function postCompensatingAdjustment(
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
        Quantity $quantityDelta,
        string $reason,
        EntityId $originalMovementId,
        ?EntityId $createdBy = null,
    ): StockMovement {
        return $this->postMovement(
            companyId: $companyId,
            variant: $variant,
            location: $location,
            movementType: StockMovementType::Adjustment,
            quantityDelta: $quantityDelta->amount(),
            reservedDelta: '0.0000',
            sourceType: 'stock_movement_correction',
            sourceId: $originalMovementId,
            reference: null,
            notes: $reason,
            createdBy: $createdBy,
        );
    }

    private function lockOrCreateBalance(
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
    ): StockBalance {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(StockBalance::class, 'b')
            ->where('b.companyId = :companyId')
            ->andWhere('b.variant = :variant')
            ->andWhere('b.location = :location')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('variant', $variant)
            ->setParameter('location', $location)
            ->getQuery();

        /** @var StockBalance|null $balance */
        $balance = $qb->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        if ($balance !== null) {
            return $balance;
        }

        $balance = new StockBalance(
            EntityId::generate(),
            $companyId,
            $variant,
            $location,
        );
        $this->entityManager->persist($balance);

        return $balance;
    }
}
