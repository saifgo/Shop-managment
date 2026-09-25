<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Inventory;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_locations')]
#[ORM\UniqueConstraint(name: 'UNIQ_STOCK_LOCATION_CODE', columns: ['company_id', 'code'])]
class StockLocation implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(name: 'is_default', options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $code,
        string $name,
        bool $isDefault = false,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->code = $code;
        $this->name = $name;
        $this->isDefault = $isDefault;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** Makes this the active default location (used when a company has no usable location). */
    public function makeActiveDefault(): void
    {
        $this->isActive = true;
        $this->isDefault = true;
    }
}
