<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Inventory\AvailabilityService;
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
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\ResetInterface;

final class ProductService implements ResetInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private PricingService $pricingService,
        private AvailabilityService $availabilityService,
    ) {
    }

    /** Available-to-sell below this is flagged as low stock (matches the dashboard and stock report). */
    public const LOW_STOCK_THRESHOLD = '5.0000';

    /**
     * Stock per variant id at the default location, loaded in one query for the variants being serialized.
     *
     * @var array<string, array{on_hand: string, available: string}>
     */
    private array $stockCache = [];

    public function reset(): void
    {
        $this->stockCache = [];
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
        ?string $status = null,
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

        if (!$portalView && $status === 'active') {
            $qb->andWhere('p.isActive = true');
        } elseif (!$portalView && $status === 'inactive') {
            $qb->andWhere('p.isActive = false');
        }

        if ($categoryId !== null && $categoryId !== '') {
            $qb->andWhere('p.category = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if ($search !== null && $search !== '') {
            // Also match variant SKUs so staff can look a product up by the code printed on the piece.
            $qb->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.slug) LIKE :search OR EXISTS (
                    SELECT sv.id FROM '.ProductVariant::class.' sv WHERE sv.product = p AND LOWER(sv.sku) LIKE :search
                )')
                ->setParameter('search', '%'.mb_strtolower(trim($search)).'%');
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $customerId = $this->resolveCustomerId($user);

        /** @var list<Product> $products */
        $products = array_values(array_filter(iterator_to_array($paginator), static fn ($p) => $p instanceof Product));
        $this->preloadStock($user, $products);

        $items = [];

        foreach ($products as $product) {
            $items[] = $this->serializeProductSummary($product, $user, $customerId);
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
        $this->assertSlugAvailable($user, $data['slug']);

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
        $this->assertSlugAvailable($user, $data['slug'], $product->getId());

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
        $this->assertSkuAvailable($user, $data['sku']);

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
     * @param array{sku: string, name: string, base_price: array{amount: string, currency: string}, attributes: array<string, string>, is_active: bool} $data
     *
     * @return array<string, mixed>
     */
    public function updateVariant(User $user, string $productId, string $variantId, array $data): array
    {
        $product = $this->findProductForUser($user, $productId);
        $variant = null;

        foreach ($product->getVariants() as $candidate) {
            if ($candidate->getId() === $variantId) {
                $variant = $candidate;
                break;
            }
        }

        if (!$variant instanceof ProductVariant) {
            throw new NotFoundHttpException('Variant not found.');
        }

        $this->assertSkuAvailable($user, $data['sku'], $variant->getId());

        $variant->update(
            sku: $data['sku'],
            name: $data['name'],
            basePrice: Money::of($data['base_price']['amount'], $data['base_price']['currency']),
            attributes: $data['attributes'],
            isActive: $data['is_active'],
        );

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

    private function assertSlugAvailable(User $user, string $slug, ?string $exceptProductId = null): void
    {
        /** @var Product|null $existing */
        $existing = $this->entityManager->getRepository(Product::class)->findOneBy([
            'companyId' => $user->companyId()->toString(),
            'slug' => $slug,
        ]);

        if ($existing !== null && $existing->getId() !== $exceptProductId) {
            throw new ConflictHttpException(sprintf('Another product already uses the slug "%s".', $slug));
        }
    }

    private function assertSkuAvailable(User $user, string $sku, ?string $exceptVariantId = null): void
    {
        /** @var ProductVariant|null $existing */
        $existing = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'companyId' => $user->companyId()->toString(),
            'sku' => $sku,
        ]);

        if ($existing !== null && $existing->getId() !== $exceptVariantId) {
            throw new ConflictHttpException(sprintf('The SKU "%s" is already used by %s.', $sku, $existing->getProduct()->getName()));
        }
    }

    /**
     * @param list<Product> $products
     */
    private function preloadStock(User $user, array $products): void
    {
        $variantIds = [];

        foreach ($products as $product) {
            foreach ($product->getVariants() as $variant) {
                $variantIds[] = $variant->getId();
            }
        }

        $location = $this->availabilityService->resolveDefaultLocation($user->companyId());

        if ($variantIds === [] || $location === null) {
            return;
        }

        /** @var list<StockBalance> $balances */
        $balances = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(StockBalance::class, 'b')
            ->where('b.companyId = :companyId')
            ->andWhere('b.location = :location')
            ->andWhere('b.variant IN (:variants)')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('location', $location)
            ->setParameter('variants', $variantIds)
            ->getQuery()
            ->getResult();

        foreach ($variantIds as $variantId) {
            $this->stockCache[$variantId] = ['on_hand' => '0.0000', 'available' => '0.0000'];
        }

        foreach ($balances as $balance) {
            $this->stockCache[$balance->getVariant()->getId()] = [
                'on_hand' => $balance->getPhysicalOnHand()->amount(),
                'available' => $balance->getAvailableToSell()->amount(),
            ];
        }
    }

    /**
     * @return array{on_hand: string, available: string}
     */
    private function stockFor(User $user, ProductVariant $variant): array
    {
        if (!isset($this->stockCache[$variant->getId()])) {
            $this->preloadStock($user, [$variant->getProduct()]);
        }

        return $this->stockCache[$variant->getId()] ?? ['on_hand' => '0.0000', 'available' => '0.0000'];
    }

    private static function stockStatus(string $available): string
    {
        if (bccomp($available, '0', 4) <= 0) {
            return 'out_of_stock';
        }

        return bccomp($available, self::LOW_STOCK_THRESHOLD, 4) < 0 ? 'low_stock' : 'in_stock';
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

        $available = '0.0000';

        foreach ($variants as $variant) {
            $available = bcadd($available, $this->positive($this->stockFor($user, $variant)['available']), 4);
        }

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
            'available_quantity' => $available,
            'stock_status' => self::stockStatus($available),
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
        $stock = $this->stockFor($user, $variant);

        $serialized = [
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
            'available_quantity' => $this->positive($stock['available']),
            'stock_status' => self::stockStatus($stock['available']),
        ];

        // Physical stock (including reserved units) is an internal figure.
        if (!$user->isPortalUser()) {
            $serialized['on_hand'] = $stock['on_hand'];
        }

        return $serialized;
    }

    private function positive(string $quantity): string
    {
        return bccomp($quantity, '0', 4) > 0 ? $quantity : '0.0000';
    }
}
