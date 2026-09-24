<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Settings;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

/** Per-company key/value setting that administrators can change at runtime. */
#[ORM\Entity]
#[ORM\Table(name: 'company_settings')]
#[ORM\UniqueConstraint(name: 'UNIQ_COMPANY_SETTING_KEY', columns: ['company_id', 'setting_key'])]
class CompanySetting implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(name: 'setting_key', length: 64)]
    private string $key;

    #[ORM\Column(name: 'setting_value', length: 255)]
    private string $value;

    #[ORM\Column(name: 'updated_by', type: 'string', length: 26, nullable: true)]
    private ?string $updatedBy;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(EntityId $id, EntityId $companyId, string $key, string $value, ?EntityId $updatedBy = null)
    {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->key = $key;
        $this->value = $value;
        $this->updatedBy = $updatedBy?->toString();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function change(string $value, ?EntityId $updatedBy): void
    {
        $this->value = $value;
        $this->updatedBy = $updatedBy?->toString();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
