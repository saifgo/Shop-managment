<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Returns;

use App\Domain\Returns\ReturnEventType;
use App\Domain\Returns\ReturnStatus;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'return_events')]
class ReturnEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ReturnRequest::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'return_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ReturnRequest $returnRequest;

    #[ORM\Column(name: 'event_type', length: 32, enumType: ReturnEventType::class)]
    private ReturnEventType $eventType;

    #[ORM\Column(name: 'from_status', length: 32, nullable: true, enumType: ReturnStatus::class)]
    private ?ReturnStatus $fromStatus;

    #[ORM\Column(name: 'to_status', length: 32, nullable: true, enumType: ReturnStatus::class)]
    private ?ReturnStatus $toStatus;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        ReturnRequest $returnRequest,
        ReturnEventType $eventType,
        ?ReturnStatus $fromStatus = null,
        ?ReturnStatus $toStatus = null,
        ?string $notes = null,
        ?EntityId $createdBy = null,
    ) {
        $this->id = $id->toString();
        $this->returnRequest = $returnRequest;
        $this->eventType = $eventType;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->notes = $notes;
        $this->createdBy = $createdBy?->toString();
        $this->createdAt = new \DateTimeImmutable();
        $returnRequest->addEvent($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): ReturnEventType
    {
        return $this->eventType;
    }

    public function getFromStatus(): ?ReturnStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?ReturnStatus
    {
        return $this->toStatus;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
