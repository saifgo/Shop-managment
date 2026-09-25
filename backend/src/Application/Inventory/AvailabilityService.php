<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Inventory\DemandStatus;
use App\Domain\Production\ProductionStatus;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use App\Infrastructure\Persistence\Entity\Production\ProductionItem;
use App\Infrastructure\Persistence\Entity\Sales\DemandAllocation;
use Doctrine\ORM\EntityManagerInterface;

final class AvailabilityService
{
    public const DEFAULT_LOCATION_CODE = 'MAIN';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     variant_id: string,
     *     location_id: string|null,
     *     physical_on_hand: string,
     *     reserved: string,
     *     available_to_sell: string,
     *     confirmed_demand: string,
     *     net_production_demand: string,
     *     already_in_production: string
     * }
     */
    public function forVariant(
        EntityId $companyId,
        ProductVariant $variant,
        ?StockLocation $location = null,
    ): array {
        $location ??= $this->resolveDefaultLocation($companyId);

        $balance = $this->entityManager->getRepository(StockBalance::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'variant' => $variant,
            'location' => $location,
        ]);

        $physicalOnHand = $balance?->getPhysicalOnHand()->amount() ?? '0.0000';
        $reserved = $balance?->getReserved()->amount() ?? '0.0000';
        $availableToSell = $balance?->getAvailableToSell()->amount() ?? '0.0000';

        $confirmedDemand = $this->sumOpenDemand($companyId, $variant);
        $alreadyInProduction = $this->sumInProduction($companyId, $variant);
        $netProductionDemand = bccomp($confirmedDemand, $availableToSell, 4) > 0
            ? bcsub($confirmedDemand, $availableToSell, 4)
            : '0.0000';

        return [
            'variant_id' => $variant->getId(),
            'location_id' => $location?->getId(),
            'physical_on_hand' => $physicalOnHand,
            'reserved' => $reserved,
            'available_to_sell' => $availableToSell,
            'confirmed_demand' => $confirmedDemand,
            'net_production_demand' => $netProductionDemand,
            'already_in_production' => $alreadyInProduction,
        ];
    }

    private function sumOpenDemand(EntityId $companyId, ProductVariant $variant): string
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(d.quantity - d.fulfilledQuantity), 0)')
            ->from(DemandAllocation::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.variant = :variant')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('variant', $variant)
            ->setParameter('statuses', [DemandStatus::Open->value, DemandStatus::PartiallyFulfilled->value]);

        // Normalise to scale 4: the raw SUM() format depends on the database driver.
        return bcadd((string) $qb->getQuery()->getSingleScalarResult(), '0', 4);
    }

    /**
     * Units committed to manufacturing (planned, running or paused orders) that have not been lost
     * at a stage yet — the "Already in production" figure of the demand view (blueprint §7.3).
     */
    private function sumInProduction(EntityId $companyId, ProductVariant $variant): string
    {
        /** @var list<ProductionItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i', 'p')
            ->from(ProductionItem::class, 'i')
            ->join('i.productionOrder', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('i.variant = :variant')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('variant', $variant)
            ->setParameter('statuses', [
                ProductionStatus::Planned->value,
                ProductionStatus::InProgress->value,
                ProductionStatus::Paused->value,
            ])
            ->getQuery()
            ->getResult();

        $total = '0.0000';
        foreach ($items as $item) {
            $total = bcadd($total, $item->getProductionOrder()->currentQuantityFor($item)->amount(), 4);
        }

        return $total;
    }

    /**
     * The location stock operations post to. A company that has none yet (fresh install, seeds never
     * run) gets a "MAIN" location, so reservations, receipts and adjustments never dead-end on setup.
     * The new location is persisted; the caller's transaction flushes it.
     */
    public function requireDefaultLocation(EntityId $companyId): StockLocation
    {
        $location = $this->resolveDefaultLocation($companyId);

        if ($location !== null) {
            return $location;
        }

        /** @var StockLocation|null $main */
        $main = $this->entityManager->getRepository(StockLocation::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'code' => self::DEFAULT_LOCATION_CODE,
        ]);

        if ($main !== null) {
            $main->makeActiveDefault();

            return $main;
        }

        $main = new StockLocation(
            EntityId::generate(),
            $companyId,
            self::DEFAULT_LOCATION_CODE,
            'Main Warehouse',
            isDefault: true,
        );
        $this->entityManager->persist($main);

        return $main;
    }

    /** Read-only lookup: null when the company has no active location yet. */
    public function resolveDefaultLocation(EntityId $companyId): ?StockLocation
    {
        /** @var StockLocation|null $location */
        $location = $this->entityManager->getRepository(StockLocation::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'isDefault' => true,
            'isActive' => true,
        ]);

        if ($location !== null) {
            return $location;
        }

        return $this->entityManager->getRepository(StockLocation::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'isActive' => true,
        ]);
    }
}
