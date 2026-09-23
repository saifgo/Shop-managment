<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Documents;

use App\Domain\Documents\DocumentRelationType;
use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_relations')]
class DocumentRelation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CommercialDocument::class)]
    #[ORM\JoinColumn(name: 'source_document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CommercialDocument $sourceDocument;

    #[ORM\ManyToOne(targetEntity: CommercialDocument::class)]
    #[ORM\JoinColumn(name: 'target_document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CommercialDocument $targetDocument;

    #[ORM\Column(name: 'relation_type', length: 32, enumType: DocumentRelationType::class)]
    private DocumentRelationType $relationType;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        CommercialDocument $sourceDocument,
        CommercialDocument $targetDocument,
        DocumentRelationType $relationType,
    ) {
        $this->id = $id->toString();
        $this->sourceDocument = $sourceDocument;
        $this->targetDocument = $targetDocument;
        $this->relationType = $relationType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSourceDocument(): CommercialDocument
    {
        return $this->sourceDocument;
    }

    public function getTargetDocument(): CommercialDocument
    {
        return $this->targetDocument;
    }

    public function getRelationType(): DocumentRelationType
    {
        return $this->relationType;
    }
}
