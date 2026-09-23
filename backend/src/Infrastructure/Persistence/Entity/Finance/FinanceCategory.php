<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Finance;

use App\Domain\Finance\FinanceCategoryType;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'finance_categories')]
#[ORM\UniqueConstraint(name: 'UNIQ_FINANCE_CATEGORY', columns: ['company_id', 'type', 'code'])]
class FinanceCategory implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32, enumType: FinanceCategoryType::class)]
    private FinanceCategoryType $type;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        FinanceCategoryType $type,
        string $code,
        string $name,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->type = $type;
        $this->code = $code;
        $this->name = $name;
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

    public function getType(): FinanceCategoryType
    {
        return $this->type;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
