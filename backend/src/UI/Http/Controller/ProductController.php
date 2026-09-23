<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Catalog\ProductService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Catalog')]
final class ProductController extends AbstractController
{
    public function __construct(private ProductService $productService)
    {
    }

    #[Route('/api/products', name: 'api_products_list', methods: ['GET'])]
    #[OA\Get(path: '/api/products', summary: 'List products', security: [['Bearer' => []]])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_VIEW);

        $portalView = $user->isPortalUser();
        $result = $this->productService->list(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            categoryId: $request->query->get('category'),
            visibility: $request->query->get('visibility'),
            search: $request->query->get('search'),
            portalView: $portalView,
        );

        return $this->json($result->toArray());
    }

    #[Route('/api/products', name: 'api_products_create', methods: ['POST'])]
    #[OA\Post(path: '/api/products', summary: 'Create product', security: [['Bearer' => []]])]
    public function create(#[MapRequestPayload] CreateProductRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_MANAGE);

        $product = $this->productService->create($user, [
            'name' => $payload->name,
            'slug' => $payload->slug,
            'description' => $payload->description,
            'visibility' => $payload->visibility,
            'backorder_policy' => $payload->backorderPolicy,
            'category_id' => $payload->categoryId,
            'media' => $payload->media,
        ]);

        return $this->json($product, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/products/{id}', name: 'api_products_get', methods: ['GET'])]
    #[OA\Get(path: '/api/products/{id}', summary: 'Get product', security: [['Bearer' => []]])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_VIEW);

        return $this->json($this->productService->get($user, $id, $user->isPortalUser()));
    }

    #[Route('/api/products/{id}', name: 'api_products_update', methods: ['PATCH'])]
    #[OA\Patch(path: '/api/products/{id}', summary: 'Update product', security: [['Bearer' => []]])]
    public function update(string $id, #[MapRequestPayload] UpdateProductRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_MANAGE);

        $product = $this->productService->update($user, $id, [
            'name' => $payload->name,
            'slug' => $payload->slug,
            'description' => $payload->description,
            'visibility' => $payload->visibility,
            'backorder_policy' => $payload->backorderPolicy,
            'category_id' => $payload->categoryId,
            'is_active' => $payload->isActive,
        ]);

        return $this->json($product);
    }

    #[Route('/api/products/{id}/variants', name: 'api_products_variants_list', methods: ['GET'])]
    #[OA\Get(path: '/api/products/{id}/variants', summary: 'List product variants', security: [['Bearer' => []]])]
    public function listVariants(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_VIEW);

        return $this->json(['items' => $this->productService->listVariants($user, $id, $user->isPortalUser())]);
    }

    #[Route('/api/products/{id}/variants', name: 'api_products_variants_create', methods: ['POST'])]
    #[OA\Post(path: '/api/products/{id}/variants', summary: 'Create product variant', security: [['Bearer' => []]])]
    public function createVariant(string $id, #[MapRequestPayload] CreateVariantRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_MANAGE);

        $variant = $this->productService->createVariant($user, $id, [
            'sku' => $payload->sku,
            'name' => $payload->name,
            'base_price' => ['amount' => $payload->basePriceAmount, 'currency' => $payload->basePriceCurrency],
            'attributes' => $payload->attributes,
        ]);

        return $this->json($variant, JsonResponse::HTTP_CREATED);
    }
}

final readonly class CreateProductRequest
{
    /**
     * @param list<array{url: string, alt_text?: string, sort_order?: int, is_primary?: bool}> $media
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
        #[Assert\NotBlank]
        public string $slug,
        public ?string $description,
        #[Assert\Choice(choices: ['public', 'hidden', 'internal'])]
        public string $visibility = 'public',
        #[Assert\Choice(choices: ['allow', 'deny'])]
        #[SerializedName('backorder_policy')]
        public string $backorderPolicy = 'allow',
        #[SerializedName('category_id')]
        public ?string $categoryId = null,
        public array $media = [],
    ) {
    }
}

final readonly class UpdateProductRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
        #[Assert\NotBlank]
        public string $slug,
        public ?string $description,
        #[Assert\Choice(choices: ['public', 'hidden', 'internal'])]
        public string $visibility,
        #[Assert\Choice(choices: ['allow', 'deny'])]
        #[SerializedName('backorder_policy')]
        public string $backorderPolicy,
        #[SerializedName('category_id')]
        public ?string $categoryId = null,
        #[SerializedName('is_active')]
        public bool $isActive = true,
    ) {
    }
}

final readonly class CreateVariantRequest
{
    /** @param array<string, string> $attributes */
    public function __construct(
        #[Assert\NotBlank]
        public string $sku,
        #[Assert\NotBlank]
        public string $name,
        #[Assert\NotBlank]
        #[SerializedName('base_price_amount')]
        public string $basePriceAmount,
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        #[SerializedName('base_price_currency')]
        public string $basePriceCurrency,
        public array $attributes = [],
    ) {
    }
}
