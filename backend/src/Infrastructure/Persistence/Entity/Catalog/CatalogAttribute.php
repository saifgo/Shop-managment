<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_attributes')]
#[ORM\UniqueConstraint(name: 'UNIQ_CATALOG_ATTR_CODE', columns: ['company_id', 'code'])]
class CatalogAttribute implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $type;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $code,
        string $name,
        string $type,
        array $options = [],
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->options = $options;
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

    public function getType(): string
    {
        return $this->type;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }
}
