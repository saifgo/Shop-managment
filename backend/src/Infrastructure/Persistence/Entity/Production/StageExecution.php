<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Production\StageExecutionStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stage_executions')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAGE_EXECUTION', columns: ['production_order_id', 'production_stage_id'])]
class StageExecution
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ProductionOrder::class, inversedBy: 'stageExecutions')]
    #[ORM\JoinColumn(name: 'production_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductionOrder $productionOrder;

    #[ORM\ManyToOne(targetEntity: ProductionStage::class)]
    #[ORM\JoinColumn(name: 'production_stage_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductionStage $productionStage;

    #[ORM\Column(name: 'stage_sequence')]
    private int $stageSequence;

    #[ORM\Column(length: 32, enumType: StageExecutionStatus::class)]
    private StageExecutionStatus $status;

    #[ORM\Column(name: 'input_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $inputQuantity = '0.0000';

    #[ORM\Column(name: 'accepted_output_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $acceptedOutputQuantity = '0.0000';

    #[ORM\Column(name: 'loss_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $lossQuantity = '0.0000';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'performed_by', type: 'string', length: 26, nullable: true)]
    private ?string $performedBy;

    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, ProductionLoss> */
    #[ORM\OneToMany(mappedBy: 'stageExecution', targetEntity: ProductionLoss::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $losses;

    public function __construct(
        EntityId $id,
        ProductionOrder $productionOrder,
        ProductionStage $productionStage,
    ) {
        $this->id = $id->toString();
        $this->productionOrder = $productionOrder;
        $this->productionStage = $productionStage;
        $this->stageSequence = $productionStage->getSequence();
        $this->status = StageExecutionStatus::Pending;
        $this->losses = new ArrayCollection();
        $productionOrder->addStageExecution($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProductionOrder(): ProductionOrder
    {
        return $this->productionOrder;
    }

    public function getProductionStage(): ProductionStage
    {
        return $this->productionStage;
    }

    public function getStageSequence(): int
    {
        return $this->stageSequence;
    }

    public function getStatus(): StageExecutionStatus
    {
        return $this->status;
    }

    public function getInputQuantity(): Quantity
    {
        return Quantity::of($this->inputQuantity);
    }

    public function getAcceptedOutputQuantity(): Quantity
    {
        return Quantity::of($this->acceptedOutputQuantity);
    }

    public function getLossQuantity(): Quantity
    {
        return Quantity::of($this->lossQuantity);
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPerformedBy(): ?string
    {
        return $this->performedBy;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** @return Collection<int, ProductionLoss> */
    public function getLosses(): Collection
    {
        return $this->losses;
    }

    public function start(Quantity $inputQuantity, ?EntityId $performedBy = null): void
    {
        if ($this->status !== StageExecutionStatus::Pending) {
            throw new \DomainException('Only pending stages can be started.');
        }

        $this->status = StageExecutionStatus::InProgress;
        $this->inputQuantity = $inputQuantity->amount();
        $this->performedBy = $performedBy?->toString();
        $this->startedAt = new \DateTimeImmutable();
    }

    public function complete(Quantity $acceptedOutput, Quantity $loss, ?string $notes = null): void
    {
        if ($this->status !== StageExecutionStatus::InProgress) {
            throw new \DomainException('Only in-progress stages can be completed.');
        }

        $this->acceptedOutputQuantity = $acceptedOutput->amount();
        $this->lossQuantity = $loss->amount();
        $this->notes = $notes;
        $this->status = StageExecutionStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function addLoss(ProductionLoss $loss): void
    {
        if (!$this->losses->contains($loss)) {
            $this->losses->add($loss);
        }
    }
}
