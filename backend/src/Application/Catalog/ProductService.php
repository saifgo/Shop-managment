<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\PaginatedResult;
use App\Domain\Catalog\BackorderPolicy;
use App\Domain\Catalog\Visibility;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Catalog\Category;
use App\Infrastructure\Persistence\Entity\Catalog\Product;
use App\Infrastructure\Persistence\Entity\Catalog\ProductMedia;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private PricingService $pricingService,
    ) {
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function list(
        User $user,
        int $page,
        int $perPage,
        ?string $categoryId,
        ?string $visibility,
        ?string $search,
        bool $portalView,
    ): PaginatedResult {
        $companyId = $user->companyId()->toString();
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('p.name', 'ASC');

        if ($portalView) {
            $qb->andWhere('p.visibility = :visibility')
                ->andWhere('p.isActive = true')
                ->setParameter('visibility', Visibility::Public->value);
        } elseif ($visibility !== null && $visibility !== '') {
            $qb->andWhere('p.visibility = :visibility')
                ->setParameter('visibility', $visibility);
        }

        if ($categoryId !== null && $categoryId !== '') {
            $qb->andWhere('p.category = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.slug) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $customerId = $this->resolveCustomerId($user);

        $items = [];

        foreach ($paginator as $product) {
            if ($product instanceof Product) {
                $items[] = $this->serializeProductSummary($product, $user, $customerId);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $user, string $productId, bool $portalView): array
    {
        $product = $this->findProductForUser($user, $productId);

        if ($portalView && ($product->getVisibility() !== Visibility::Public || !$product->isActive())) {
            throw new NotFoundHttpException('Product not found.');
        }

        return $this->serializeProductDetail($product, $user, $this->resolveCustomerId($user));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        $category = $this->resolveCategory($user, $data['category_id'] ?? null);

        $product = new Product(
            id: EntityId::generate(),
            companyId: $user->companyId(),
            name: $data['name'],
            slug: $data['slug'],
            visibility: Visibility::from($data['visibility']),
            backorderPolicy: BackorderPolicy::from($data['backorder_policy']),
            category: $category,
            description: $data['description'] ?? null,
        );

        $this->entityManager->persist($product);
        $this->persistMedia($product, $data['media'] ?? []);
        $this->unitOfWork->flush();

        return $this->serializeProductDetail($product, $user, null);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(User $user, string $productId, array $data): array
    {
        $product = $this->findProductForUser($user, $productId);
        $category = $this->resolveCategory($user, $data['category_id'] ?? null);

        $product->update(
            name: $data['name'],
            slug: $data['slug'],
            description: $data['description'] ?? null,
            visibility: Visibility::from($data['visibility']),
            backorderPolicy: BackorderPolicy::from($data['backorder_policy']),
            category: $category,
            isActive: (bool) ($data['is_active'] ?? true),
        );

        $this->unitOfWork->flush();

        return $this->serializeProductDetail($product, $user, null);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createVariant(User $user, string $productId, array $data): array
    {
        $product = $this->findProductForUser($user, $productId);

        $variant = new ProductVariant(
            id: EntityId::generate(),
            companyId: $user->companyId(),
            product: $product,
            sku: $data['sku'],
            name: $data['name'],
            basePrice: Money::of($data['base_price']['amount'], $data['base_price']['currency']),
            attributes: $data['attributes'] ?? [],
        );

        $this->entityManager->persist($variant);
        $this->unitOfWork->flush();

        return $this->serializeVariant($variant, $user, null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVariants(User $user, string $productId, bool $portalView): array
    {
        $product = $this->findProductForUser($user, $productId);

        if ($portalView && ($product->getVisibility() !== Visibility::Public || !$product->isActive())) {
            throw new NotFoundHttpException('Product not found.');
        }

        $customerId = $this->resolveCustomerId($user);
        $variants = [];

        foreach ($product->getVariants() as $variant) {
            if ($portalView && !$variant->isActive()) {
                continue;
            }

            $variants[] = $this->serializeVariant($variant, $user, $customerId);
        }

        return $variants;
    }

    private function findProductForUser(User $user, string $productId): Product
    {
        /** @var Product|null $product */
        $product = $this->entityManager->getRepository(Product::class)->findOneBy([
            'id' => $productId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        return $product;
    }

    private function resolveCategory(User $user, ?string $categoryId): ?Category
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        /** @var Category|null $category */
        $category = $this->entityManager->getRepository(Category::class)->findOneBy([
            'id' => $categoryId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($category === null) {
            throw new NotFoundHttpException('Category not found.');
        }

        return $category;
    }

    /**
     * @param list<array{url: string, alt_text?: string, sort_order?: int, is_primary?: bool}> $mediaItems
     */
    private function persistMedia(Product $product, array $mediaItems): void
    {
        foreach ($mediaItems as $index => $item) {
            $media = new ProductMedia(
                id: EntityId::generate(),
                product: $product,
                url: $item['url'],
                altText: $item['alt_text'] ?? null,
                sortOrder: $item['sort_order'] ?? $index,
                isPrimary: (bool) ($item['is_primary'] ?? $index === 0),
            );
            $this->entityManager->persist($media);
        }
    }

    private function resolveCustomerId(User $user): ?EntityId
    {
        if (!$user->isPortalUser()) {
            return null;
        }

        $portalUser = $this->entityManager->getRepository(\App\Infrastructure\Persistence\Entity\Customer\PortalUser::class)
            ->findOneBy(['user' => $user]);

        return $portalUser !== null
            ? EntityId::fromString($portalUser->getCustomer()->getId())
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProductSummary(Product $product, User $user, ?EntityId $customerId): array
    {
        $variants = $product->getVariants()->filter(static fn (ProductVariant $v) => $v->isActive());
        $fromPrice = null;

        if (!$variants->isEmpty()) {
            $prices = [];

            foreach ($variants as $variant) {
                $resolved = $this->pricingService->resolveForVariant($variant, $user->companyId(), $customerId);
                $prices[] = $resolved['amount'];
            }

            sort($prices);
            $firstVariant = $variants->first();

            if ($firstVariant instanceof ProductVariant) {
                $resolved = $this->pricingService->resolveForVariant($firstVariant, $user->companyId(), $customerId);
                $fromPrice = [
                    'amount' => $prices[0],
                    'currency' => $resolved['currency'],
                ];
            }
        }

        $primaryMedia = $product->getMedia()->filter(static fn (ProductMedia $m) => $m->isPrimary())->first()
            ?: $product->getMedia()->first();

        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'description' => $product->getDescription(),
            'visibility' => $product->getVisibility()->value,
            'backorder_policy' => $product->getBackorderPolicy()->value,
            'is_active' => $product->isActive(),
            'category_id' => $product->getCategory()?->getId(),
            'category_name' => $product->getCategory()?->getName(),
            'from_price' => $fromPrice,
            'primary_image_url' => $primaryMedia instanceof ProductMedia ? $primaryMedia->getUrl() : null,
            'variant_count' => $variants->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProductDetail(Product $product, User $user, ?EntityId $customerId): array
    {
        $summary = $this->serializeProductSummary($product, $user, $customerId);

        $summary['media'] = array_map(
            static fn (ProductMedia $media) => [
                'id' => $media->getId(),
                'url' => $media->getUrl(),
                'alt_text' => $media->getAltText(),
                'sort_order' => $media->getSortOrder(),
                'is_primary' => $media->isPrimary(),
            ],
            $product->getMedia()->toArray(),
        );

        $summary['variants'] = array_map(
            fn (ProductVariant $variant) => $this->serializeVariant($variant, $user, $customerId),
            $product->getVariants()->toArray(),
        );

        $summary['availability_message'] = $product->getBackorderPolicy() === BackorderPolicy::Allow
            ? 'Available for backorder when out of stock.'
            : 'Only available while in stock.';

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVariant(ProductVariant $variant, User $user, ?EntityId $customerId): array
    {
        $price = $this->pricingService->resolveForVariant($variant, $user->companyId(), $customerId);

        return [
            'id' => $variant->getId(),
            'sku' => $variant->getSku(),
            'name' => $variant->getName(),
            'attributes' => $variant->getAttributes(),
            'is_active' => $variant->isActive(),
            'price' => $price,
            'base_price' => [
                'amount' => $variant->getBasePrice()->amount(),
                'currency' => $variant->getBasePrice()->currency(),
            ],
        ];
    }
}
