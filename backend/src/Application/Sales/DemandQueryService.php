<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\AvailabilityService;
use App\Domain\Inventory\DemandStatus;
use App\Domain\Sales\OrderStatus;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Sales\DemandAllocation;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use Doctrine\ORM\EntityManagerInterface;

final class DemandQueryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AvailabilityService $availabilityService,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function byCustomer(User $user, ?string $customerId = null, ?string $variantId = null): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d', 'oi', 'o', 'v', 'p', 'c')
            ->from(DemandAllocation::class, 'd')
            ->join('d.orderItem', 'oi')
            ->join('oi.order', 'o')
            ->join('d.variant', 'v')
            ->join('v.product', 'p')
            ->join('d.customer', 'c')
            ->where('d.companyId = :companyId')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('o.status NOT IN (:cancelled)')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('statuses', [DemandStatus::Open->value, DemandStatus::PartiallyFulfilled->value])
            ->setParameter('cancelled', [OrderStatus::Cancelled->value])
            ->orderBy('c.displayName', 'ASC')
            ->addOrderBy('p.name', 'ASC');

        if ($customerId !== null) {
            $qb->andWhere('c.id = :customerId')->setParameter('customerId', $customerId);
        }

        if ($variantId !== null) {
            $qb->andWhere('v.id = :variantId')->setParameter('variantId', $variantId);
        }

        /** @var list<DemandAllocation> $demands */
        $demands = $qb->getQuery()->getResult();

        $rows = [];

        foreach ($demands as $demand) {
            $rows[] = $this->serializeDemandRow($demand);
        }

        return ['items' => $this->aggregateCustomerRows($rows)];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function byProduct(User $user, ?string $variantId = null): array
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
                'ready' => '0.0000',
                'delivered' => '0.0000',
                'on_hand' => '0.0000',
                'net_demand' => '0.0000',
                'already_in_production' => '0.0000',
                'to_produce' => '0.0000',
            ];

            $row['ordered'] = bcadd($row['ordered'], $item->getQuantityOrdered()->amount(), 4);
            $row['reserved'] = bcadd($row['reserved'], $item->getQuantityReserved()->amount(), 4);
            $row['backordered'] = bcadd($row['backordered'], $item->getQuantityBackordered()->amount(), 4);

            if ($item->getLineStatus()->value === 'READY') {
                $row['ready'] = bcadd($row['ready'], $item->getQuantityReserved()->amount(), 4);
            }

            $row['delivered'] = bcadd($row['delivered'], $item->getQuantityDelivered()->amount(), 4);
            $aggregated[$key] = $row;
        }

        foreach ($aggregated as $variantKey => &$row) {
            $variant = $this->entityManager->getReference(
                \App\Infrastructure\Persistence\Entity\Catalog\ProductVariant::class,
                $variantKey,
            );
            $availability = $this->availabilityService->forVariant($user->companyId(), $variant);

            $row['on_hand'] = $availability['physical_on_hand'];
            $row['net_demand'] = $availability['net_production_demand'];
            $row['already_in_production'] = $availability['already_in_production'];
            $toProduce = bcsub($row['net_demand'], $row['already_in_production'], 4);
            $row['to_produce'] = bccomp($toProduce, '0', 4) > 0 ? $toProduce : '0.0000';
        }

        return ['items' => array_values($aggregated)];
    }

    /** @return array<string, mixed> */
    private function serializeDemandRow(DemandAllocation $demand): array
    {
        $item = $demand->getOrderItem();
        $order = $item->getOrder();

        return [
            'product_id' => $item->getProductId(),
            'product_name' => $item->getProductName(),
            'variant_id' => $demand->getVariant()->getId(),
            'variant_name' => $item->getVariantName(),
            'sku' => $item->getSku(),
            'customer_id' => $demand->getCustomer()->getId(),
            'customer_name' => $demand->getCustomer()->getDisplayName(),
            'order_id' => $order->getId(),
            'order_reference' => $order->getReference(),
            'ordered' => $item->getQuantityOrdered()->amount(),
            'reserved' => $item->getQuantityReserved()->amount(),
            'backordered' => $demand->getOpenQuantity()->amount(),
            'ready' => $item->getLineStatus()->value === 'READY' ? $item->getQuantityReserved()->amount() : '0.0000',
            'delivered' => $item->getQuantityDelivered()->amount(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function aggregateCustomerRows(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $aggregated */
        $aggregated = [];

        foreach ($rows as $row) {
            $key = $row['customer_id'].'|'.$row['variant_id'];
            $existing = $aggregated[$key] ?? $row;
            $existing['ordered'] = bcadd($existing['ordered'], $row['ordered'], 4);
            $existing['reserved'] = bcadd($existing['reserved'], $row['reserved'], 4);
            $existing['backordered'] = bcadd($existing['backordered'], $row['backordered'], 4);
            $existing['ready'] = bcadd($existing['ready'], $row['ready'], 4);
            $existing['delivered'] = bcadd($existing['delivered'], $row['delivered'], 4);
            $aggregated[$key] = $existing;
        }

        return array_values($aggregated);
    }
}
