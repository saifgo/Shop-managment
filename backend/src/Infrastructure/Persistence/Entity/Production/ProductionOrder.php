<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Production\ProductionPriority;
use App\Domain\Production\ProductionStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_orders')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCTION_REFERENCE', columns: ['company_id', 'reference'])]
class ProductionOrder implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: 32, enumType: ProductionStatus::class)]
    private ProductionStatus $status;

    #[ORM\Column(length: 16, enumType: ProductionPriority::class)]
    private ProductionPriority $priority;

    #[ORM\Column(name: 'source_type', length: 64, nullable: true)]
    private ?string $sourceType;

    #[ORM\Column(name: 'source_id', type: 'string', length: 26, nullable: true)]
    private ?string $sourceId;

    #[ORM\Column(name: 'planned_start', nullable: true)]
    private ?\DateTimeImmutable $plannedStart;

    #[ORM\Column(name: 'planned_due', nullable: true)]
    private ?\DateTimeImmutable $plannedDue;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'assigned_manager', type: 'string', length: 26, nullable: true)]
    private ?string $assignedManager;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'cancelled_at', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var Collection<int, ProductionItem> */
    #[ORM\OneToMany(mappedBy: 'productionOrder', targetEntity: ProductionItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, StageExecution> */
    #[ORM\OneToMany(mappedBy: 'productionOrder', targetEntity: StageExecution::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['stageSequence' => 'ASC'])]
    private Collection $stageExecutions;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $reference,
        ProductionPriority $priority = ProductionPriority::Normal,
        ?string $sourceType = null,
        ?EntityId $sourceId = null,
        ?\DateTimeImmutable $plannedStart = null,
        ?\DateTimeImmutable $plannedDue = null,
        ?string $notes = null,
        ?EntityId $createdBy = null,
        ?EntityId $assignedManager = null,
        ?string $idempotencyKey = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->reference = $reference;
        $this->status = ProductionStatus::Draft;
        $this->priority = $priority;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId?->toString();
        $this->plannedStart = $plannedStart;
        $this->plannedDue = $plannedDue;
        $this->notes = $notes;
        $this->createdBy = $createdBy?->toString();
        $this->assignedManager = $assignedManager?->toString();
        $this->idempotencyKey = $idempotencyKey;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->items = new ArrayCollection();
        $this->stageExecutions = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getStatus(): ProductionStatus
    {
        return $this->status;
    }

    public function getPriority(): ProductionPriority
    {
        return $this->priority;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function getPlannedStart(): ?\DateTimeImmutable
    {
        return $this->plannedStart;
    }

    public function getPlannedDue(): ?\DateTimeImmutable
    {
        return $this->plannedDue;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getAssignedManager(): ?string
    {
        return $this->assignedManager;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /** @return Collection<int, ProductionItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, StageExecution> */
    public function getStageExecutions(): Collection
    {
        return $this->stageExecutions;
    }

    public function addItem(ProductionItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }

    public function addStageExecution(StageExecution $execution): void
    {
        if (!$this->stageExecutions->contains($execution)) {
            $this->stageExecutions->add($execution);
        }
    }

    public function transitionTo(ProductionStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        if ($status === ProductionStatus::InProgress && $this->startedAt === null) {
            $this->startedAt = new \DateTimeImmutable();
        }

        if ($status === ProductionStatus::Completed) {
            $this->completedAt = new \DateTimeImmutable();
        }

        if ($status === ProductionStatus::Cancelled) {
            $this->cancelledAt = new \DateTimeImmutable();
        }
    }

    public function markPlanned(): void
    {
        $this->transitionTo(ProductionStatus::Planned);
    }

    public function getPrimaryItem(): ProductionItem
    {
        $item = $this->items->first();

        if ($item === false) {
            throw new \DomainException('Production order has no items.');
        }

        return $item;
    }

    public function getCurrentStageExecution(): ?StageExecution
    {
        foreach ($this->stageExecutions as $execution) {
            if ($execution->getStatus() === \App\Domain\Production\StageExecutionStatus::InProgress) {
                return $execution;
            }
        }

        foreach ($this->stageExecutions as $execution) {
            if ($execution->getStatus() === \App\Domain\Production\StageExecutionStatus::Pending) {
                return $execution;
            }
        }

        return null;
    }

    public function getLastCompletedStageExecution(): ?StageExecution
    {
        $last = null;

        foreach ($this->stageExecutions as $execution) {
            if ($execution->getStatus() === \App\Domain\Production\StageExecutionStatus::Completed) {
                $last = $execution;
            }
        }

        return $last;
    }

    public function allStagesCompleted(): bool
    {
        if ($this->stageExecutions->isEmpty()) {
            return false;
        }

        foreach ($this->stageExecutions as $execution) {
            if ($execution->getStatus() !== \App\Domain\Production\StageExecutionStatus::Completed) {
                return false;
            }
        }

        return true;
    }
}
