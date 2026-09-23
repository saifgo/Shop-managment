<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Catalog\CategoryService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Catalog')]
final class CategoryController extends AbstractController
{
    public function __construct(private CategoryService $categoryService)
    {
    }

    #[Route('/api/categories', name: 'api_categories_list', methods: ['GET'])]
    #[OA\Get(path: '/api/categories', summary: 'List category tree', security: [['Bearer' => []]])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_VIEW);

        return $this->json(['items' => $this->categoryService->tree($user)]);
    }

    #[Route('/api/categories', name: 'api_categories_create', methods: ['POST'])]
    #[OA\Post(path: '/api/categories', summary: 'Create category', security: [['Bearer' => []]])]
    public function create(#[MapRequestPayload] CreateCategoryRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_MANAGE);

        $category = $this->categoryService->create($user, [
            'name' => $payload->name,
            'slug' => $payload->slug,
            'parent_id' => $payload->parentId,
            'sort_order' => $payload->sortOrder,
        ]);

        return $this->json($category, JsonResponse::HTTP_CREATED);
    }
}

final readonly class CreateCategoryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
        #[Assert\NotBlank]
        public string $slug,
        #[SerializedName('parent_id')]
        public ?string $parentId = null,
        #[SerializedName('sort_order')]
        public int $sortOrder = 0,
    ) {
    }
}
