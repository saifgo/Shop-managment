<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Production;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_loss_reasons')]
#[ORM\UniqueConstraint(name: 'UNIQ_LOSS_REASON_CODE', columns: ['company_id', 'code'])]
class ProductionLossReason implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $label;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $code,
        string $label,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->code = $code;
        $this->label = $label;
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function update(string $label, bool $isActive): void
    {
        $this->label = $label;
        $this->isActive = $isActive;
    }
}
