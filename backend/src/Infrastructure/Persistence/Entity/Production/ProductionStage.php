<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Production\ReconciliationMode;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_stages')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCTION_STAGE_SEQ', columns: ['company_id', 'sequence'])]
class ProductionStage implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(name: 'can_record_quantity', options: ['default' => true])]
    private bool $canRecordQuantity = true;

    #[ORM\Column(name: 'can_record_loss', options: ['default' => true])]
    private bool $canRecordLoss = true;

    #[ORM\Column(name: 'reconciliation_mode', length: 32, enumType: ReconciliationMode::class)]
    private ReconciliationMode $reconciliationMode;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        int $sequence,
        string $name,
        ReconciliationMode $reconciliationMode = ReconciliationMode::Strict,
        bool $canRecordQuantity = true,
        bool $canRecordLoss = true,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->sequence = $sequence;
        $this->name = $name;
        $this->reconciliationMode = $reconciliationMode;
        $this->canRecordQuantity = $canRecordQuantity;
        $this->canRecordLoss = $canRecordLoss;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function canRecordQuantity(): bool
    {
        return $this->canRecordQuantity;
    }

    public function canRecordLoss(): bool
    {
        return $this->canRecordLoss;
    }

    public function getReconciliationMode(): ReconciliationMode
    {
        return $this->reconciliationMode;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
