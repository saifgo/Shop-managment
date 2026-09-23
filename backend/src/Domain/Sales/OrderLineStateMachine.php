<?php

declare(strict_types=1);

namespace App\Domain\Sales;

final class OrderLineStateMachine
{
    /** @var array<string, list<OrderLineStatus>> */
    private const TRANSITIONS = [
        'UNALLOCATED' => [OrderLineStatus::Reserved, OrderLineStatus::Backordered],
        'RESERVED' => [OrderLineStatus::Ready, OrderLineStatus::Backordered],
        'BACKORDERED' => [OrderLineStatus::Reserved, OrderLineStatus::Ready],
        'READY' => [OrderLineStatus::Delivered],
        'DELIVERED' => [],
    ];

    public function canTransition(OrderLineStatus $from, OrderLineStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertTransition(OrderLineStatus $from, OrderLineStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf(
                'Invalid order line status transition from %s to %s.',
                $from->value,
                $to->value,
            ));
        }
    }
}
