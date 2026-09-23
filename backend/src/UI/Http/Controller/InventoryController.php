<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Inventory\InventoryService;
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

#[OA\Tag(name: 'Inventory')]
final class InventoryController extends AbstractController
{
    public function __construct(private InventoryService $inventoryService)
    {
    }

    #[Route('/api/inventory/stock', name: 'api_inventory_stock', methods: ['GET'])]
    #[OA\Get(path: '/api/inventory/stock', summary: 'List stock balances', security: [['Bearer' => []]])]
    public function stock(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::INVENTORY_VIEW);

        $result = $this->inventoryService->listStock(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            variantId: $request->query->get('variant_id'),
            locationId: $request->query->get('location_id'),
        );

        return $this->json($result->toArray());
    }

    #[Route('/api/inventory/availability', name: 'api_inventory_availability', methods: ['GET'])]
    #[OA\Get(path: '/api/inventory/availability', summary: 'Get variant availability projection', security: [['Bearer' => []]])]
    public function availability(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::INVENTORY_VIEW);

        $variantId = $request->query->get('variant_id');

        if ($variantId === null || $variantId === '') {
            return $this->json(['error' => 'variant_id is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->json($this->inventoryService->getAvailability(
            user: $user,
            variantId: $variantId,
            locationId: $request->query->get('location_id'),
        ));
    }

    #[Route('/api/inventory/movements', name: 'api_inventory_movements', methods: ['GET'])]
    #[OA\Get(path: '/api/inventory/movements', summary: 'List stock movements', security: [['Bearer' => []]])]
    public function movements(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::INVENTORY_VIEW);

        $result = $this->inventoryService->listMovements(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            variantId: $request->query->get('variant_id'),
            locationId: $request->query->get('location_id'),
            sourceType: $request->query->get('source_type'),
            sourceId: $request->query->get('source_id'),
        );

        return $this->json($result->toArray());
    }

    #[Route('/api/inventory/adjustments', name: 'api_inventory_adjustments', methods: ['POST'])]
    #[OA\Post(path: '/api/inventory/adjustments', summary: 'Create stock adjustment', security: [['Bearer' => []]])]
    public function adjust(#[MapRequestPayload] CreateAdjustmentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::INVENTORY_ADJUST);

        $result = $this->inventoryService->createAdjustment(
            user: $user,
            variantId: $payload->variantId,
            locationId: $payload->locationId,
            quantityDelta: $payload->quantityDelta,
            reason: $payload->reason,
        );

        return $this->json($result, JsonResponse::HTTP_CREATED);
    }
}

final readonly class CreateAdjustmentRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('variant_id')]
        public string $variantId,
        #[Assert\NotBlank]
        #[SerializedName('location_id')]
        public string $locationId,
        #[Assert\NotBlank]
        #[SerializedName('quantity_delta')]
        public string $quantityDelta,
        #[Assert\NotBlank]
        public string $reason,
    ) {
    }
}
