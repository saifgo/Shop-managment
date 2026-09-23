<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Documents;

use App\Domain\Documents\DocumentType;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_sequences')]
#[ORM\UniqueConstraint(name: 'UNIQ_DOC_SEQUENCE', columns: ['company_id', 'document_type', 'fiscal_year'])]
class DocumentSequence
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(name: 'document_type', length: 32, enumType: DocumentType::class)]
    private DocumentType $documentType;

    #[ORM\Column(name: 'fiscal_year', type: 'integer')]
    private int $fiscalYear;

    #[ORM\Column(name: 'last_number', type: 'integer')]
    private int $lastNumber = 0;

    public function __construct(EntityId $id, EntityId $companyId, DocumentType $documentType, int $fiscalYear)
    {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->documentType = $documentType;
        $this->fiscalYear = $fiscalYear;
    }

    public function nextNumber(): int
    {
        ++$this->lastNumber;

        return $this->lastNumber;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }
}
