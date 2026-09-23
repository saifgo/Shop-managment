<?php

declare(strict_types=1);

namespace App\Domain\Sales;

final class OrderStateMachine
{
    /** @var array<string, list<OrderStatus>> */
    private const TRANSITIONS = [
        'DRAFT' => [OrderStatus::Submitted, OrderStatus::Cancelled],
        'SUBMITTED' => [OrderStatus::Confirmed, OrderStatus::Cancelled],
        'CONFIRMED' => [OrderStatus::PartiallyAllocated, OrderStatus::ReadyToDeliver, OrderStatus::Cancelled],
        'PARTIALLY_ALLOCATED' => [OrderStatus::ReadyToDeliver, OrderStatus::PartiallyDelivered, OrderStatus::Cancelled],
        'READY_TO_DELIVER' => [OrderStatus::PartiallyDelivered, OrderStatus::Delivered],
        'PARTIALLY_DELIVERED' => [OrderStatus::PartiallyDelivered, OrderStatus::Delivered],
        'DELIVERED' => [],
        'CANCELLED' => [],
    ];

    public function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertTransition(OrderStatus $from, OrderStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf(
                'Invalid order status transition from %s to %s.',
                $from->value,
                $to->value,
            ));
        }
    }
}
