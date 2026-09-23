<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Inventory\AvailabilityService;
use App\Domain\Production\ProductionStatus;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Production\ProductionItem;
use App\Infrastructure\Persistence\Entity\Production\ProductionOrder;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Domain\Sales\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class ProductionDemandQueryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AvailabilityService $availabilityService,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function demand(User $user, ?string $variantId = null): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('oi', 'o', 'v', 'p')
            ->from(OrderItem::class, 'oi')
            ->join('oi.order', 'o')
            ->join('oi.variant', 'v')
            ->join('v.product', 'p')
            ->where('o.companyId = :companyId')
            ->andWhere('o.status NOT IN (:terminal)')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('terminal', [OrderStatus::Cancelled->value, OrderStatus::Delivered->value])
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('v.sku', 'ASC');

        if ($variantId !== null) {
            $qb->andWhere('v.id = :variantId')->setParameter('variantId', $variantId);
        }

        /** @var list<OrderItem> $items */
        $items = $qb->getQuery()->getResult();

        /** @var array<string, array<string, mixed>> $aggregated */
        $aggregated = [];

        foreach ($items as $item) {
            $key = $item->getVariant()->getId();
            $row = $aggregated[$key] ?? [
                'product_id' => $item->getProductId(),
                'product_name' => $item->getProductName(),
                'variant_id' => $item->getVariant()->getId(),
                'variant_name' => $item->getVariantName(),
                'sku' => $item->getSku(),
                'ordered' => '0.0000',
                'reserved' => '0.0000',
                'backordered' => '0.0000',
                'on_hand' => '0.0000',
                'net_demand' => '0.0000',
                'already_in_production' => '0.0000',
                'to_produce' => '0.0000',
            ];

            $row['ordered'] = bcadd($row['ordered'], $item->getQuantityOrdered()->amount(), 4);
            $row['reserved'] = bcadd($row['reserved'], $item->getQuantityReserved()->amount(), 4);
            $row['backordered'] = bcadd($row['backordered'], $item->getQuantityBackordered()->amount(), 4);
            $aggregated[$key] = $row;
        }

        foreach ($aggregated as $variantKey => &$row) {
            $variant = $this->entityManager->getReference(ProductVariant::class, $variantKey);
            $availability = $this->availabilityService->forVariant($user->companyId(), $variant);
            $inProduction = $this->sumInProduction($user, $variantKey);

            $row['on_hand'] = $availability['physical_on_hand'];
            $row['net_demand'] = $availability['net_production_demand'];
            $row['already_in_production'] = $inProduction;
            $toProduce = bcsub($row['net_demand'], $inProduction, 4);
            $row['to_produce'] = bccomp($toProduce, '0', 4) > 0 ? $toProduce : '0.0000';
        }

        return ['items' => array_values($aggregated)];
    }

    private function sumInProduction(User $user, string $variantId): string
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(i.plannedQuantity), 0)')
            ->from(ProductionItem::class, 'i')
            ->join('i.productionOrder', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('i.variant = :variantId')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('variantId', $variantId)
            ->setParameter('statuses', [
                ProductionStatus::Planned->value,
                ProductionStatus::InProgress->value,
                ProductionStatus::Paused->value,
            ]);

        return (string) $qb->getQuery()->getSingleScalarResult();
    }
}
