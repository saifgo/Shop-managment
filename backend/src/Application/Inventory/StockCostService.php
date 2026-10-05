<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Inventory\StockMovement;
use Doctrine\ORM\EntityManagerInterface;

/** Read-side view of what an item currently costs the workshop. */
final class StockCostService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Average cost of one unit across every location holding it. With nothing on hand, the cost of
     * the most recent receipt, so a recipe can still be priced while the clay bin is empty.
     */
    public function unitCost(EntityId $companyId, ProductVariant $variant): string
    {
        /** @var list<StockBalance> $balances */
        $balances = $this->entityManager->getRepository(StockBalance::class)->findBy([
            'companyId' => $companyId->toString(),
            'variant' => $variant,
        ]);

        $quantity = '0.0000';
        $value = '0.0000';

        foreach ($balances as $balance) {
            $quantity = bcadd($quantity, $balance->getPhysicalOnHand()->amount(), 4);
            $value = bcadd($value, $balance->getValue(), 4);
        }

        if (bccomp($quantity, '0', 4) > 0) {
            return bcadd(bcdiv($value, $quantity, 6), '0.00005', 4);
        }

        foreach ($balances as $balance) {
            if (bccomp($balance->getAverageCost(), '0', 4) > 0) {
                return $balance->getAverageCost();
            }
        }

        $last = $this->entityManager->createQueryBuilder()
            ->select('m.unitCost')
            ->from(StockMovement::class, 'm')
            ->where('m.companyId = :companyId')
            ->andWhere('m.variant = :variant')
            ->andWhere('m.unitCost IS NOT NULL')
            ->andWhere('m.quantityDelta > 0')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('variant', $variant)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($last) && isset($last['unitCost']) ? (string) $last['unitCost'] : '0.0000';
    }
}
