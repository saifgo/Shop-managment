<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_losses')]
class ProductionLoss
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: StageExecution::class, inversedBy: 'losses')]
    #[ORM\JoinColumn(name: 'stage_execution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StageExecution $stageExecution;

    #[ORM\ManyToOne(targetEntity: ProductionLossReason::class)]
    #[ORM\JoinColumn(name: 'loss_reason_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductionLossReason $lossReason;

    #[ORM\Column(name: 'reason_code', length: 32, nullable: true)]
    private ?string $reasonCode;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'recorded_by', type: 'string', length: 26, nullable: true)]
    private ?string $recordedBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        StageExecution $stageExecution,
        Quantity $quantity,
        ?ProductionLossReason $lossReason = null,
        ?string $reasonCode = null,
        ?string $notes = null,
        ?EntityId $recordedBy = null,
    ) {
        $this->id = $id->toString();
        $this->stageExecution = $stageExecution;
        $this->quantity = $quantity->amount();
        $this->lossReason = $lossReason;
        $this->reasonCode = $reasonCode ?? $lossReason?->getCode();
        $this->notes = $notes;
        $this->recordedBy = $recordedBy?->toString();
        $this->createdAt = new \DateTimeImmutable();
        $stageExecution->addLoss($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStageExecution(): StageExecution
    {
        return $this->stageExecution;
    }

    public function getLossReason(): ?ProductionLossReason
    {
        return $this->lossReason;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
