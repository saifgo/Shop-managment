<?php

declare(strict_types=1);

namespace App\Domain\Sales;

final class DeliveryStateMachine
{
    /** @var array<string, list<DeliveryStatus>> */
    private const TRANSITIONS = [
        'READY_TO_DELIVER' => [DeliveryStatus::Packed, DeliveryStatus::DeliveryException],
        'PACKED' => [DeliveryStatus::Dispatched, DeliveryStatus::DeliveryException],
        'DISPATCHED' => [DeliveryStatus::InTransit, DeliveryStatus::Delivered, DeliveryStatus::DeliveryException],
        'IN_TRANSIT' => [DeliveryStatus::Delivered, DeliveryStatus::DeliveryException],
        'DELIVERED' => [],
        'DELIVERY_EXCEPTION' => [DeliveryStatus::Packed, DeliveryStatus::Dispatched],
    ];

    public function canTransition(DeliveryStatus $from, DeliveryStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertTransition(DeliveryStatus $from, DeliveryStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf(
                'Invalid delivery status transition from %s to %s.',
                $from->value,
                $to->value,
            ));
        }
    }
}
