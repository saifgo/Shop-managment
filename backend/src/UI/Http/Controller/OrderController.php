<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Documents\DocumentService;
use App\Application\Sales\DeliveryService;
use App\Application\Sales\OrderService;
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

#[OA\Tag(name: 'Orders')]
final class OrderController extends AbstractController
{
    public function __construct(
        private OrderService $orderService,
        private DeliveryService $deliveryService,
        private DocumentService $documentService,
    ) {
    }

    #[Route('/api/orders', name: 'api_orders_list', methods: ['GET'])]
    #[OA\Get(path: '/api/orders', summary: 'List orders', security: [['Bearer' => []]])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyOrderAccess($user);

        $result = $this->orderService->list(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            status: $request->query->get('status'),
            customerId: $request->query->get('customer_id'),
        );

        return $this->json($result->toArray());
    }

    #[Route('/api/orders/{id}', name: 'api_orders_get', methods: ['GET'])]
    #[OA\Get(path: '/api/orders/{id}', summary: 'Get order detail', security: [['Bearer' => []]])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyOrderAccess($user);

        return $this->json($this->orderService->get($user, $id));
    }

    #[Route('/api/orders', name: 'api_orders_create', methods: ['POST'])]
    #[OA\Post(path: '/api/orders', summary: 'Create order', security: [['Bearer' => []]])]
    public function create(Request $request, #[MapRequestPayload] CreateOrderRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);
        } else {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);
        }

        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);
        $order = $this->orderService->create(
            user: $user,
            items: $payload->items,
            customerId: $payload->customerId,
            notes: $payload->notes,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return $this->json($order, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/orders/{id}/confirm', name: 'api_orders_confirm', methods: ['POST'])]
    #[OA\Post(path: '/api/orders/{id}/confirm', summary: 'Confirm order', security: [['Bearer' => []]])]
    public function confirm(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);

        return $this->json($this->orderService->confirm($user, $id));
    }

    #[Route('/api/orders/{id}/cancel', name: 'api_orders_cancel', methods: ['POST'])]
    #[OA\Post(path: '/api/orders/{id}/cancel', summary: 'Cancel order', security: [['Bearer' => []]])]
    public function cancel(string $id, #[MapRequestPayload] CancelOrderRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);
        } else {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);
        }

        return $this->json($this->orderService->cancel($user, $id, $payload->reason));
    }

    #[Route('/api/orders/{id}/reserve', name: 'api_orders_reserve', methods: ['POST'])]
    #[OA\Post(path: '/api/orders/{id}/reserve', summary: 'Reserve stock for order', security: [['Bearer' => []]])]
    public function reserve(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);

        return $this->json($this->orderService->reserve($user, $id));
    }

    #[Route('/api/orders/{id}/create-delivery', name: 'api_orders_create_delivery', methods: ['POST'])]
    #[OA\Post(path: '/api/orders/{id}/create-delivery', summary: 'Create delivery from order', security: [['Bearer' => []]])]
    public function createDelivery(string $id, Request $request, #[MapRequestPayload] CreateDeliveryRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        return $this->json(
            $this->deliveryService->createFromOrder(
                user: $user,
                orderId: $id,
                lines: $payload->lines,
                notes: $payload->notes,
                idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            ),
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/api/orders/{id}/documents/sales-order', name: 'api_orders_sales_order_document', methods: ['POST'])]
    #[OA\Post(path: '/api/orders/{id}/documents/sales-order', summary: 'Create sales order document', security: [['Bearer' => []]])]
    public function salesOrderDocument(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        return $this->json(
            $this->documentService->createSalesOrderDocument($user, $id, is_string($idempotencyKey) ? $idempotencyKey : null),
            JsonResponse::HTTP_CREATED,
        );
    }

    private function denyOrderAccess(User $user): void
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);

            return;
        }

        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_VIEW);
    }
}

final readonly class CreateOrderRequest
{
    /**
     * @param list<array{variant_id: string, quantity: string}> $items
     */
    public function __construct(
        /** @var list<array{variant_id: string, quantity: string}> */
        #[Assert\Count(min: 1)]
        public array $items,
        #[SerializedName('customer_id')]
        public ?string $customerId = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class CancelOrderRequest
{
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}
