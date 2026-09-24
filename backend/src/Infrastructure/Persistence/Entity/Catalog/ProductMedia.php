<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Shared\EntityId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_media')]
class ProductMedia
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'media')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 512)]
    private string $url;

    #[ORM\Column(name: 'alt_text', length: 255, nullable: true)]
    private ?string $altText;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_primary', options: ['default' => false])]
    private bool $isPrimary = false;

    /** Set for uploaded files; null for media that points at an external URL. */
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey;

    #[ORM\Column(name: 'mime_type', length: 100, nullable: true)]
    private ?string $mimeType;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Product $product,
        string $url,
        ?string $altText = null,
        int $sortOrder = 0,
        bool $isPrimary = false,
        ?string $storageKey = null,
        ?string $mimeType = null,
    ) {
        $this->id = $id->toString();
        $this->product = $product;
        $this->url = $url;
        $this->altText = $altText;
        $this->sortOrder = $sortOrder;
        $this->isPrimary = $isPrimary;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->createdAt = new \DateTimeImmutable();
        $product->addMedia($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setPrimary(bool $isPrimary): void
    {
        $this->isPrimary = $isPrimary;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }
}
