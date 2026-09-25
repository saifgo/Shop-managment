<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quantities for one product (production item) at one stage. All products of an order move
 * through the stages together; each stage keeps one line per product.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stage_execution_lines')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAGE_EXECUTION_LINE', columns: ['stage_execution_id', 'production_item_id'])]
class StageExecutionLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: StageExecution::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'stage_execution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StageExecution $stageExecution;

    #[ORM\ManyToOne(targetEntity: ProductionItem::class)]
    #[ORM\JoinColumn(name: 'production_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductionItem $productionItem;

    #[ORM\Column(name: 'input_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $inputQuantity;

    #[ORM\Column(name: 'accepted_output_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $acceptedOutputQuantity = '0.0000';

    #[ORM\Column(name: 'loss_quantity', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $lossQuantity = '0.0000';

    public function __construct(
        EntityId $id,
        StageExecution $stageExecution,
        ProductionItem $productionItem,
        Quantity $inputQuantity,
    ) {
        $this->id = $id->toString();
        $this->stageExecution = $stageExecution;
        $this->productionItem = $productionItem;
        $this->inputQuantity = $inputQuantity->amount();
        $stageExecution->addLine($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStageExecution(): StageExecution
    {
        return $this->stageExecution;
    }

    public function getProductionItem(): ProductionItem
    {
        return $this->productionItem;
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

    public function record(Quantity $acceptedOutput, Quantity $loss): void
    {
        $this->acceptedOutputQuantity = $acceptedOutput->amount();
        $this->lossQuantity = $loss->amount();
    }
}
