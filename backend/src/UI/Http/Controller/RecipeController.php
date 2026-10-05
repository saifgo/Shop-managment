<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Catalog\RecipeService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Catalog')]
final class RecipeController extends AbstractController
{
    public function __construct(private RecipeService $recipeService)
    {
    }

    #[Route('/api/variants/{variantId}/recipe', name: 'api_variant_recipe_get', methods: ['GET'])]
    #[OA\Get(path: '/api/variants/{variantId}/recipe', summary: 'Raw materials one unit of the variant consumes, with their current cost', security: [['Bearer' => []]])]
    public function get(string $variantId, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_VIEW);

        return $this->json($this->recipeService->get($user, $variantId));
    }

    #[Route('/api/variants/{variantId}/recipe', name: 'api_variant_recipe_replace', methods: ['PUT'])]
    #[OA\Put(path: '/api/variants/{variantId}/recipe', summary: 'Replace the recipe of a variant', security: [['Bearer' => []]])]
    public function replace(string $variantId, #[MapRequestPayload] ReplaceRecipeRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CATALOG_PRODUCTS_MANAGE);

        return $this->json($this->recipeService->replace($user, $variantId, $payload->components));
    }
}

final readonly class ReplaceRecipeRequest
{
    /**
     * @param list<array{component_variant_id?: string|null, quantity_per_unit?: string|null}> $components
     */
    public function __construct(
        #[Assert\NotNull]
        public array $components,
    ) {
    }
}
