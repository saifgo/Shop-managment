<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Production\ProductionConfigService;
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

/** Production workflow configuration: stages and loss reasons (blueprint §8.1, §14 Settings). */
#[OA\Tag(name: 'Production')]
final class ProductionConfigController extends AbstractController
{
    public function __construct(private ProductionConfigService $configService)
    {
    }

    #[Route('/api/production/stages', name: 'api_production_stages_list', methods: ['GET'])]
    #[OA\Get(path: '/api/production/stages', summary: 'List production stages in workflow order (defaults are created on first use)', security: [['Bearer' => []]])]
    public function listStages(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        return $this->json($this->configService->listStages($user));
    }

    #[Route('/api/production/stages', name: 'api_production_stages_create', methods: ['POST'])]
    #[OA\Post(path: '/api/production/stages', summary: 'Add a stage at the end of the workflow', security: [['Bearer' => []]])]
    public function createStage(#[MapRequestPayload] ProductionStageRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->configService->createStage($user, $payload->toArray()), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/production/stages/order', name: 'api_production_stages_reorder', methods: ['PUT'])]
    #[OA\Put(path: '/api/production/stages/order', summary: 'Reorder stages; running productions keep their original order', security: [['Bearer' => []]])]
    public function reorderStages(#[MapRequestPayload] ReorderStagesRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->configService->reorderStages($user, $payload->stageIds));
    }

    #[Route('/api/production/stages/{id}', name: 'api_production_stages_update', methods: ['PATCH'])]
    #[OA\Patch(path: '/api/production/stages/{id}', summary: 'Rename, reconfigure, activate or deactivate a stage', security: [['Bearer' => []]])]
    public function updateStage(string $id, #[MapRequestPayload] ProductionStageRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->configService->updateStage($user, $id, $payload->toArray()));
    }

    #[Route('/api/production/loss-reasons', name: 'api_production_loss_reasons_list', methods: ['GET'])]
    #[OA\Get(path: '/api/production/loss-reasons', summary: 'List loss reasons (defaults are created on first use)', security: [['Bearer' => []]])]
    public function listLossReasons(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        return $this->json($this->configService->listLossReasons($user));
    }

    #[Route('/api/production/loss-reasons', name: 'api_production_loss_reasons_create', methods: ['POST'])]
    #[OA\Post(path: '/api/production/loss-reasons', summary: 'Add a loss reason', security: [['Bearer' => []]])]
    public function createLossReason(#[MapRequestPayload] LossReasonRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->configService->createLossReason($user, $payload->toArray()), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/production/loss-reasons/{id}', name: 'api_production_loss_reasons_update', methods: ['PATCH'])]
    #[OA\Patch(path: '/api/production/loss-reasons/{id}', summary: 'Rename, activate or deactivate a loss reason', security: [['Bearer' => []]])]
    public function updateLossReason(string $id, #[MapRequestPayload] LossReasonRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_MANAGE);

        return $this->json($this->configService->updateLossReason($user, $id, $payload->toArray()));
    }
}

final readonly class ProductionStageRequest
{
    public function __construct(
        public ?string $name = null,
        #[SerializedName('reconciliation_mode')]
        public ?string $reconciliationMode = null,
        #[SerializedName('can_record_quantity')]
        public ?bool $canRecordQuantity = null,
        #[SerializedName('can_record_loss')]
        public ?bool $canRecordLoss = null,
        #[SerializedName('is_active')]
        public ?bool $isActive = null,
    ) {
    }

    /** @return array{name: ?string, reconciliation_mode: ?string, can_record_quantity: ?bool, can_record_loss: ?bool, is_active: ?bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'reconciliation_mode' => $this->reconciliationMode,
            'can_record_quantity' => $this->canRecordQuantity,
            'can_record_loss' => $this->canRecordLoss,
            'is_active' => $this->isActive,
        ];
    }
}

final readonly class ReorderStagesRequest
{
    /** @param list<string> $stageIds */
    public function __construct(
        #[SerializedName('stage_ids')]
        public array $stageIds = [],
    ) {
    }
}

final readonly class LossReasonRequest
{
    public function __construct(
        public ?string $code = null,
        public ?string $label = null,
        #[SerializedName('is_active')]
        public ?bool $isActive = null,
    ) {
    }

    /** @return array{code: ?string, label: ?string, is_active: ?bool} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'is_active' => $this->isActive];
    }
}
