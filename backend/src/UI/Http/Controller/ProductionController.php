<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Production\ProductionDemandQueryService;
use App\Application\Production\ProductionService;
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

#[OA\Tag(name: 'Production')]
final class ProductionController extends AbstractController
{
    public function __construct(
        private ProductionService $productionService,
        private ProductionDemandQueryService $productionDemandQueryService,
    ) {
    }

    #[Route('/api/productions', name: 'api_productions_list', methods: ['GET'])]
    #[OA\Get(path: '/api/productions', summary: 'List production orders', security: [['Bearer' => []]])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        $result = $this->productionService->list(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            status: $request->query->get('status'),
            variantId: $request->query->get('variant_id'),
            stageId: $request->query->get('stage_id'),
        );

        return $this->json($result->toArray());
    }

    #[Route('/api/productions', name: 'api_productions_create', methods: ['POST'])]
    #[OA\Post(path: '/api/productions', summary: 'Create production order', security: [['Bearer' => []]])]
    public function create(
        Request $request,
        #[MapRequestPayload] CreateProductionRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        $result = $this->productionService->create(
            user: $user,
            payload: [
                'variant_id' => $payload->variantId,
                'planned_quantity' => $payload->plannedQuantity,
                'priority' => $payload->priority,
                'planned_start' => $payload->plannedStart,
                'planned_due' => $payload->plannedDue,
                'notes' => $payload->notes,
                'plan' => $payload->plan,
                'source_type' => $payload->sourceType,
                'source_id' => $payload->sourceId,
            ],
            idempotencyKey: $request->headers->get('Idempotency-Key'),
        );

        return $this->json($result, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/productions/{id}', name: 'api_productions_get', methods: ['GET'])]
    #[OA\Get(path: '/api/productions/{id}', summary: 'Get production order', security: [['Bearer' => []]])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        return $this->json($this->productionService->get($user, $id));
    }

    #[Route('/api/productions/{id}/start', name: 'api_productions_start', methods: ['POST'])]
    public function start(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->productionService->start($user, $id));
    }

    #[Route('/api/productions/{id}/pause', name: 'api_productions_pause', methods: ['POST'])]
    public function pause(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->productionService->pause($user, $id));
    }

    #[Route('/api/productions/{id}/cancel', name: 'api_productions_cancel', methods: ['POST'])]
    public function cancel(string $id, #[MapRequestPayload] CancelProductionRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->productionService->cancel($user, $id, $payload->reason));
    }

    #[Route('/api/productions/{id}/stages/{stageId}/start', name: 'api_productions_stage_start', methods: ['POST'])]
    public function startStage(
        string $id,
        string $stageId,
        #[MapRequestPayload] StartStageRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_STAGE_EXECUTE);

        return $this->json($this->productionService->startStage($user, $id, $stageId, [
            'input_quantity' => $payload->inputQuantity,
        ]));
    }

    #[Route('/api/productions/{id}/stages/{stageId}/complete', name: 'api_productions_stage_complete', methods: ['POST'])]
    public function completeStage(
        string $id,
        string $stageId,
        #[MapRequestPayload] CompleteStageRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_STAGE_EXECUTE);

        return $this->json($this->productionService->completeStage($user, $id, $stageId, [
            'accepted_output_quantity' => $payload->acceptedOutputQuantity,
            'loss_quantity' => $payload->lossQuantity ?? '0.0000',
            'notes' => $payload->notes,
            'losses' => $payload->losses,
        ]));
    }

    #[Route('/api/productions/{id}/history', name: 'api_productions_history', methods: ['GET'])]
    public function history(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        return $this->json($this->productionService->history($user, $id));
    }

    #[Route('/api/production-demand', name: 'api_production_demand', methods: ['GET'])]
    public function demand(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        return $this->json($this->productionDemandQueryService->demand(
            $user,
            $request->query->get('variant_id'),
        ));
    }
}

final readonly class CreateProductionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('variant_id')]
        public string $variantId,
        #[Assert\NotBlank]
        #[SerializedName('planned_quantity')]
        public string $plannedQuantity,
        public ?string $priority = 'NORMAL',
        #[SerializedName('planned_start')]
        public ?string $plannedStart = null,
        #[SerializedName('planned_due')]
        public ?string $plannedDue = null,
        public ?string $notes = null,
        public bool $plan = false,
        #[SerializedName('source_type')]
        public ?string $sourceType = null,
        #[SerializedName('source_id')]
        public ?string $sourceId = null,
    ) {
    }
}

final readonly class CancelProductionRequest
{
    public function __construct(public ?string $reason = null)
    {
    }
}

final readonly class StartStageRequest
{
    public function __construct(
        #[SerializedName('input_quantity')]
        public ?string $inputQuantity = null,
    ) {
    }
}

final readonly class CompleteStageRequest
{
    /**
     * @param list<array{loss_reason_id?: string|null, reason_code?: string|null, quantity: string, notes?: string|null}>|null $losses
     */
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('accepted_output_quantity')]
        public string $acceptedOutputQuantity,
        #[SerializedName('loss_quantity')]
        public ?string $lossQuantity = '0.0000',
        public ?string $notes = null,
        public ?array $losses = null,
    ) {
    }
}
