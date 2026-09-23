<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Audit;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable audit log entry. Updates and deletes are prohibited at the application layer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_events')]
class AuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26, nullable: true)]
    private ?string $companyId;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(length: 128)]
    private string $action;

    #[ORM\Column(name: 'entity_type', length: 128, nullable: true)]
    private ?string $entityType;

    #[ORM\Column(name: 'entity_id', type: 'string', length: 26, nullable: true)]
    private ?string $entityId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(name: 'user_agent', length: 512, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'correlation_id', length: 36, nullable: true)]
    private ?string $correlationId;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        EntityId $id,
        string $action,
        array $payload,
        ?EntityId $companyId = null,
        ?EntityId $actorUserId = null,
        ?string $entityType = null,
        ?EntityId $entityId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
    ) {
        $this->id = $id->toString();
        $this->action = $action;
        $this->payload = $payload;
        $this->companyId = $companyId?->toString();
        $this->actorUserId = $actorUserId?->toString();
        $this->entityType = $entityType;
        $this->entityId = $entityId?->toString();
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->correlationId = $correlationId;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
