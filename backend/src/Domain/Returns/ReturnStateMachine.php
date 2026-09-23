<?php

declare(strict_types=1);

namespace App\Domain\Returns;

final class ReturnStateMachine
{
    /** @var array<string, list<ReturnStatus>> */
    private const TRANSITIONS = [
        'REQUESTED' => [ReturnStatus::Approved],
        'APPROVED' => [ReturnStatus::Received],
        'RECEIVED' => [ReturnStatus::Inspected],
        'INSPECTED' => [ReturnStatus::Resolved],
        'RESOLVED' => [],
    ];

    public function canTransition(ReturnStatus $from, ReturnStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertTransition(ReturnStatus $from, ReturnStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException(sprintf(
                'Invalid return status transition from %s to %s.',
                $from->value,
                $to->value,
            ));
        }
    }
}
