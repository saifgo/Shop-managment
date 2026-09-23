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

        return (string) $qb->getQuery()->getSingleScalarResult();
    }

    private function sumInProduction(EntityId $companyId, ProductVariant $variant): string
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(i.plannedQuantity), 0)')
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
            ]);

        return (string) $qb->getQuery()->getSingleScalarResult();
    }

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
