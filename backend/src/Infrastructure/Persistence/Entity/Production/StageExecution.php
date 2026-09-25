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
    private ?string $notes = null;

    #[ORM\Column(name: 'performed_by', type: 'string', length: 26, nullable: true)]
    private ?string $performedBy = null;

    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, ProductionLoss> */
    #[ORM\OneToMany(mappedBy: 'stageExecution', targetEntity: ProductionLoss::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $losses;

    /** @var Collection<int, StageExecutionLine> */
    #[ORM\OneToMany(mappedBy: 'stageExecution', targetEntity: StageExecutionLine::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

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
        $this->lines = new ArrayCollection();
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

    /** @return Collection<int, StageExecutionLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getLineForItem(ProductionItem $item): ?StageExecutionLine
    {
        foreach ($this->lines as $line) {
            if ($line->getProductionItem()->getId() === $item->getId()) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Starts the stage with one input quantity per product of the order.
     *
     * @param list<array{item: ProductionItem, quantity: Quantity}> $inputs
     */
    public function start(array $inputs, ?EntityId $performedBy = null): void
    {
        if ($this->status !== StageExecutionStatus::Pending) {
            throw new \DomainException('Only pending stages can be started.');
        }

        $total = Quantity::zero();
        foreach ($inputs as $input) {
            new StageExecutionLine(EntityId::generate(), $this, $input['item'], $input['quantity']);
            $total = $total->add($input['quantity']);
        }

        $this->status = StageExecutionStatus::InProgress;
        $this->inputQuantity = $total->amount();
        $this->performedBy = $performedBy?->toString();
        $this->startedAt = new \DateTimeImmutable();
    }

    /**
     * Completes the stage; accepted output and loss must already be recorded on every line.
     */
    public function complete(?string $notes = null): void
    {
        if ($this->status !== StageExecutionStatus::InProgress) {
            throw new \DomainException('Only in-progress stages can be completed.');
        }

        $accepted = Quantity::zero();
        $loss = Quantity::zero();
        foreach ($this->lines as $line) {
            $accepted = $accepted->add($line->getAcceptedOutputQuantity());
            $loss = $loss->add($line->getLossQuantity());
        }

        $this->acceptedOutputQuantity = $accepted->amount();
        $this->lossQuantity = $loss->amount();
        $this->notes = $notes;
        $this->status = StageExecutionStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function addLine(StageExecutionLine $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    public function addLoss(ProductionLoss $loss): void
    {
        if (!$this->losses->contains($loss)) {
            $this->losses->add($loss);
        }
    }
}
