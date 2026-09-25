<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Sales\DeliveryService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Http\Middleware\IdempotencyKeySubscriber;
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

#[OA\Tag(name: 'Deliveries')]
final class DeliveryController extends AbstractController
{
    public function __construct(private DeliveryService $deliveryService)
    {
    }

    #[Route('/api/deliveries', name: 'api_deliveries_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyDeliveryAccess($user);

        return $this->json($this->deliveryService->list(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            orderId: $request->query->get('order_id'),
        )->toArray());
    }

    #[Route('/api/deliveries/{id}', name: 'api_deliveries_get', methods: ['GET'])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyDeliveryAccess($user);

        return $this->json($this->deliveryService->get($user, $id));
    }

    #[Route('/api/deliveries/{id}/transition', name: 'api_deliveries_transition', methods: ['POST'])]
    public function transition(string $id, #[MapRequestPayload] TransitionDeliveryRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);

        return $this->json($this->deliveryService->transition($user, $id, $payload->status));
    }

    private function denyDeliveryAccess(User $user): void
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);

            return;
        }

        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_VIEW);
    }
}

final readonly class TransitionDeliveryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['PACKED', 'DISPATCHED', 'IN_TRANSIT', 'DELIVERED', 'DELIVERY_EXCEPTION'])]
        public string $status,
    ) {
    }
}
