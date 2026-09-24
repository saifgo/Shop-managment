<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Returns\ReturnService;
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
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Returns')]
final class ReturnController extends AbstractController
{
    public function __construct(private ReturnService $returnService)
    {
    }

    #[Route('/api/returns', name: 'api_returns_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyReturnAccess($user);

        return $this->json($this->returnService->list(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            orderId: $request->query->get('order_id'),
            status: $request->query->get('status'),
        )->toArray());
    }

    #[Route('/api/returns', name: 'api_returns_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] CreateReturnRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);
        } else {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::RETURNS_MANAGE);
        }

        $result = $this->returnService->create(
            $user,
            [
                'order_id' => $payload->order_id,
                'reason' => $payload->reason,
                'notes' => $payload->notes,
                'items' => $payload->items,
            ],
            $request->headers->get(IdempotencyKeySubscriber::HEADER_NAME),
        );

        return $this->json($result, 201);
    }

    #[Route('/api/returns/{id}', name: 'api_returns_get', methods: ['GET'])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyReturnAccess($user);

        return $this->json($this->returnService->get($user, $id));
    }

    #[Route('/api/returns/{id}/approve', name: 'api_returns_approve', methods: ['POST'])]
    public function approve(string $id, #[MapRequestPayload] ReturnActionRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::RETURNS_MANAGE);

        return $this->json($this->returnService->approve($user, $id, $payload->notes));
    }

    #[Route('/api/returns/{id}/receive', name: 'api_returns_receive', methods: ['POST'])]
    public function receive(string $id, #[MapRequestPayload] ReturnActionRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::RETURNS_MANAGE);

        return $this->json($this->returnService->receive($user, $id, $payload->notes));
    }

    #[Route('/api/returns/{id}/inspect', name: 'api_returns_inspect', methods: ['POST'])]
    public function inspect(string $id, #[MapRequestPayload] InspectReturnRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::RETURNS_MANAGE);

        return $this->json($this->returnService->inspect($user, $id, $payload->items, $payload->notes));
    }

    #[Route('/api/returns/{id}/resolve', name: 'api_returns_resolve', methods: ['POST'])]
    public function resolve(string $id, Request $request, #[MapRequestPayload] ResolveReturnRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::RETURNS_MANAGE);

        return $this->json($this->returnService->resolve(
            $user,
            $id,
            [
                'resolution' => $payload->resolution,
                'invoice_id' => $payload->invoice_id,
                'notes' => $payload->notes,
            ],
            $request->headers->get(IdempotencyKeySubscriber::HEADER_NAME),
        ));
    }

    private function denyReturnAccess(User $user): void
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);

            return;
        }

        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::RETURNS_VIEW);
    }
}

final readonly class CreateReturnRequest
{
    /** @param list<array{order_item_id: string, quantity: string, delivery_line_id?: string, reason?: string}> $items */
    public function __construct(
        #[Assert\NotBlank]
        public string $order_id,
        #[Assert\Count(min: 1)]
        public array $items,
        public ?string $reason = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class ReturnActionRequest
{
    public function __construct(public ?string $notes = null)
    {
    }
}

final readonly class InspectReturnRequest
{
    /** @param list<array{return_item_id: string, condition: string, notes?: string}> $items */
    public function __construct(
        #[Assert\Count(min: 1)]
        public array $items,
        public ?string $notes = null,
    ) {
    }
}

final readonly class ResolveReturnRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['REFUND', 'EXCHANGE', 'REPLACEMENT', 'CREDIT_NOTE', 'REJECTED'])]
        public string $resolution,
        public ?string $invoice_id = null,
        public ?string $notes = null,
    ) {
    }
}
