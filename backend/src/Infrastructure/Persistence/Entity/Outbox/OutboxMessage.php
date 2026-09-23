<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Outbox;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_messages')]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'aggregate_type', length: 128)]
    private string $aggregateType;

    #[ORM\Column(name: 'aggregate_id', type: 'string', length: 26)]
    private string $aggregateId;

    #[ORM\Column(name: 'event_type', length: 128)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        EntityId $id,
        string $aggregateType,
        EntityId $aggregateId,
        string $eventType,
        array $payload,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->id = $id->toString();
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId->toString();
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function markPublished(): void
    {
        $this->publishedAt = new \DateTimeImmutable();
    }

    public function incrementAttempts(): void
    {
        ++$this->attempts;
    }
}
