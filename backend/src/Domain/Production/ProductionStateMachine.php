<?php

declare(strict_types=1);

namespace App\Domain\Production;

final class ProductionStateMachine
{
    /** @var array<string, list<ProductionStatus>> */
    private const TRANSITIONS = [
        'DRAFT' => [ProductionStatus::Planned, ProductionStatus::InProgress, ProductionStatus::Cancelled],
        'PLANNED' => [ProductionStatus::InProgress, ProductionStatus::Cancelled],
        'IN_PROGRESS' => [ProductionStatus::Paused, ProductionStatus::Completed, ProductionStatus::Cancelled],
        'PAUSED' => [ProductionStatus::InProgress, ProductionStatus::Cancelled],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    public function canTransition(ProductionStatus $from, ProductionStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertTransition(ProductionStatus $from, ProductionStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf(
                'Invalid production status transition from %s to %s.',
                $from->value,
                $to->value,
            ));
        }
    }
}
