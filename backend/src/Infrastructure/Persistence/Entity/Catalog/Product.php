<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Catalog;

use App\Domain\Catalog\BackorderPolicy;
use App\Domain\Catalog\Visibility;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCTS_SLUG', columns: ['company_id', 'slug'])]
class Product implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 220)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(length: 16, enumType: Visibility::class)]
    private Visibility $visibility;

    #[ORM\Column(name: 'backorder_policy', length: 16, enumType: BackorderPolicy::class)]
    private BackorderPolicy $backorderPolicy;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductVariant> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $variants;

    /** @var Collection<int, ProductMedia> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductMedia::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $media;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        string $name,
        string $slug,
        Visibility $visibility,
        BackorderPolicy $backorderPolicy,
        ?Category $category = null,
        ?string $description = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->name = $name;
        $this->slug = $slug;
        $this->visibility = $visibility;
        $this->backorderPolicy = $backorderPolicy;
        $this->category = $category;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->variants = new ArrayCollection();
        $this->media = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function getBackorderPolicy(): BackorderPolicy
    {
        return $this->backorderPolicy;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    /** @return Collection<int, ProductVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    /** @return Collection<int, ProductMedia> */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function addVariant(ProductVariant $variant): void
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
        }
    }

    public function addMedia(ProductMedia $media): void
    {
        if (!$this->media->contains($media)) {
            $this->media->add($media);
        }
    }

    public function removeMedia(ProductMedia $media): void
    {
        $this->media->removeElement($media);
    }

    public function update(
        string $name,
        string $slug,
        ?string $description,
        Visibility $visibility,
        BackorderPolicy $backorderPolicy,
        ?Category $category,
        bool $isActive,
    ): void {
        $this->name = $name;
        $this->slug = $slug;
        $this->description = $description;
        $this->visibility = $visibility;
        $this->backorderPolicy = $backorderPolicy;
        $this->category = $category;
        $this->isActive = $isActive;
        $this->touch();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
