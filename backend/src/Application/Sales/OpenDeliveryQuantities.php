<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Domain\Sales\DeliveryStatus;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Sales\DeliveryLine;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quantities already assigned to deliveries that have not been completed yet.
 *
 * An order line's delivered quantity only moves when a delivery is marked DELIVERED, so without this
 * the same units could be put on several open deliveries and shipped twice.
 */
final class OpenDeliveryQuantities
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, Quantity> keyed by order item id
     */
    public function forOrder(Order $order): array
    {
        /** @var list<DeliveryLine> $lines */
        $lines = $this->entityManager->createQueryBuilder()
            ->select('l', 'd')
            ->from(DeliveryLine::class, 'l')
            ->join('l.delivery', 'd')
            ->where('d.order = :order')
            ->andWhere('d.status != :delivered')
            ->setParameter('order', $order)
            ->setParameter('delivered', DeliveryStatus::Delivered->value)
            ->getQuery()
            ->getResult();

        $pending = [];

        foreach ($lines as $line) {
            // Replacement shipments (from returns) do not count against the ordered quantity.
            if (str_contains((string) $line->getDelivery()->getNotes(), 'Replacement')) {
                continue;
            }

            $itemId = $line->getOrderItem()->getId();
            $pending[$itemId] = ($pending[$itemId] ?? Quantity::zero())->add($line->getQuantity());
        }

        return $pending;
    }

    /**
     * @param array<string, Quantity> $pending
     */
    public static function deliverable(OrderItem $item, array $pending): Quantity
    {
        $remaining = $item->getRemainingDeliverableQuantity();
        $open = $pending[$item->getId()] ?? Quantity::zero();

        return $open->compare($remaining) >= 0 ? Quantity::zero() : $remaining->subtract($open);
    }
}
