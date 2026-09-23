<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Documents;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_files')]
class DocumentFile
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CommercialDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CommercialDocument $document;

    #[ORM\Column(name: 'storage_key', length: 512)]
    private string $storageKey;

    #[ORM\Column(name: 'mime_type', length: 64)]
    private string $mimeType;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(name: 'generated_at')]
    private \DateTimeImmutable $generatedAt;

    public function __construct(
        EntityId $id,
        CommercialDocument $document,
        string $storageKey,
        string $mimeType,
        int $version = 1,
    ) {
        $this->id = $id->toString();
        $this->document = $document;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->version = $version;
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDocument(): CommercialDocument
    {
        return $this->document;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }
}
