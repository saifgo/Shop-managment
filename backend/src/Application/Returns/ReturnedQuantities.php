<?php

declare(strict_types=1);

namespace App\Application\Returns;

use App\Domain\Returns\ReturnResolution;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Returns\ReturnItem;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Units of each order line already claimed by return requests.
 *
 * Rejected returns free their units again, and so do exchanges/replacements: the customer
 * received new pieces, which they may in turn need to send back.
 */
final class ReturnedQuantities
{
    private const RELEASING_RESOLUTIONS = [
        ReturnResolution::Rejected,
        ReturnResolution::Exchange,
        ReturnResolution::Replacement,
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, Quantity> keyed by order item id
     */
    public function forOrder(Order $order): array
    {
        /** @var list<ReturnItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('ri', 'r')
            ->from(ReturnItem::class, 'ri')
            ->join('ri.returnRequest', 'r')
            ->where('r.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();

        $claimed = [];

        foreach ($items as $item) {
            if (in_array($item->getReturnRequest()->getResolution(), self::RELEASING_RESOLUTIONS, true)) {
                continue;
            }

            $itemId = $item->getOrderItem()->getId();
            $claimed[$itemId] = ($claimed[$itemId] ?? Quantity::zero())->add($item->getQuantity());
        }

        return $claimed;
    }

    /**
     * @param array<string, Quantity> $claimed
     */
    public static function returnable(OrderItem $item, array $claimed): Quantity
    {
        $delivered = $item->getQuantityDelivered();
        $already = $claimed[$item->getId()] ?? Quantity::zero();

        return $already->compare($delivered) >= 0 ? Quantity::zero() : $delivered->subtract($already);
    }
}
