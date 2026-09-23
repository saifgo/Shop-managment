<?php

declare(strict_types=1);

namespace App\Domain\Shared;

abstract readonly class DomainEvent
{
    public function __construct(
        public EntityId $aggregateId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    abstract public function eventName(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function payload(): array;
}
