<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Audit\AuditRecorder;
use App\Domain\Production\ReconciliationMode;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Production\ProductionLossReason;
use App\Infrastructure\Persistence\Entity\Production\ProductionStage;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Configurable production workflow (blueprint §8.1): the ordered stages every production order
 * moves through, and the loss reasons operators pick when units are lost at a stage.
 *
 * A company that has never configured production gets the default workflow the first time it is
 * needed, so a fresh install never dead-ends on "no stages configured".
 */
final class ProductionConfigService
{
    /** @var list<string> */
    public const DEFAULT_STAGES = [
        'Preparing Materials',
        'Making the Product',
        'Refining',
        'First Oven',
        'Decoration',
        'Second Oven',
        'Sorting',
    ];

    /** @var array<string, string> code => label */
    public const DEFAULT_LOSS_REASONS = [
        'BROKEN' => 'Broken / unusable',
        'CRACKS' => 'Cracks',
        'KILN_DAMAGE' => 'Kiln damage',
        'DECORATION_REJECT' => 'Decoration reject',
        'FIRING_DAMAGE' => 'Firing damage',
        'QUALITY_REJECT' => 'Quality reject',
        'OTHER' => 'Other',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private AuditRecorder $auditRecorder,
    ) {
    }

    /**
     * Creates the default stages and/or loss reasons for a company that has none. Safe to call on
     * every request; flushes only when it created something.
     */
    public function ensureDefaults(EntityId $companyId): void
    {
        $created = false;

        if ($this->stageRepository()->findOneBy(['companyId' => $companyId->toString()]) === null) {
            foreach (self::DEFAULT_STAGES as $index => $name) {
                $this->entityManager->persist(new ProductionStage(
                    id: EntityId::generate(),
                    companyId: $companyId,
                    sequence: $index + 1,
                    name: $name,
                    reconciliationMode: ReconciliationMode::Strict,
                ));
            }
            $created = true;
        }

        if ($this->lossReasonRepository()->findOneBy(['companyId' => $companyId->toString()]) === null) {
            foreach (self::DEFAULT_LOSS_REASONS as $code => $label) {
                $this->entityManager->persist(new ProductionLossReason(
                    id: EntityId::generate(),
                    companyId: $companyId,
                    code: $code,
                    label: $label,
                ));
            }
            $created = true;
        }

        if ($created) {
            // Flush now so the repository queries that follow in this request see the new rows.
            $this->entityManager->flush();
        }
    }

    /** @return list<ProductionStage> active stages in workflow order */
    public function activeStages(EntityId $companyId): array
    {
        $this->ensureDefaults($companyId);

        /** @var list<ProductionStage> $stages */
        $stages = $this->stageRepository()->findBy(
            ['companyId' => $companyId->toString(), 'isActive' => true],
            ['sequence' => 'ASC'],
        );

        return $stages;
    }

    /**
     * Resolves the loss reason an operator picked, by id or by code. Only active reasons of the
     * company are accepted, so every recorded loss is explainable (blueprint §8.4).
     */
    public function requireActiveLossReason(EntityId $companyId, ?string $reasonId, ?string $reasonCode): ProductionLossReason
    {
        $this->ensureDefaults($companyId);

        $criteria = ['companyId' => $companyId->toString(), 'isActive' => true];

        if ($reasonId !== null && $reasonId !== '') {
            $criteria['id'] = $reasonId;
        } elseif ($reasonCode !== null && $reasonCode !== '') {
            $criteria['code'] = strtoupper(trim($reasonCode));
        } else {
            throw new BadRequestHttpException('Choose a loss reason.');
        }

        /** @var ProductionLossReason|null $reason */
        $reason = $this->lossReasonRepository()->findOneBy($criteria);

        if ($reason === null) {
            throw new BadRequestHttpException('Unknown or inactive loss reason.');
        }

        return $reason;
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listStages(User $user): array
    {
        return $this->unitOfWork->transactional(function () use ($user): array {
            $this->ensureDefaults($user->companyId());

            return ['items' => array_map($this->serializeStage(...), $this->allStages($user->companyId()))];
        });
    }

    /**
     * @param array{name?: string|null, reconciliation_mode?: string|null, can_record_quantity?: bool|null, can_record_loss?: bool|null} $payload
     *
     * @return array<string, mixed>
     */
    public function createStage(User $user, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload): array {
            $companyId = $user->companyId();
            $this->ensureDefaults($companyId);
            $stages = $this->allStages($companyId);
            $last = end($stages);

            $stage = new ProductionStage(
                id: EntityId::generate(),
                companyId: $companyId,
                sequence: $last instanceof ProductionStage ? $last->getSequence() + 1 : 1,
                name: $this->requireName($payload['name'] ?? null),
                reconciliationMode: $this->parseMode($payload['reconciliation_mode'] ?? null) ?? ReconciliationMode::Strict,
                canRecordQuantity: $payload['can_record_quantity'] ?? true,
                canRecordLoss: $payload['can_record_loss'] ?? true,
            );
            $this->entityManager->persist($stage);
            $this->audit($user, 'production.stage.created', 'production_stage', $stage->getId(), $this->serializeStage($stage));

            return $this->serializeStage($stage);
        });
    }

    /**
     * @param array{name?: string|null, reconciliation_mode?: string|null, can_record_quantity?: bool|null, can_record_loss?: bool|null, is_active?: bool|null} $payload
     *
     * @return array<string, mixed>
     */
    public function updateStage(User $user, string $stageId, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $stageId, $payload): array {
            $stage = $this->findStage($user, $stageId);
            $before = $this->serializeStage($stage);
            $isActive = $payload['is_active'] ?? $stage->isActive();

            if (!$isActive && $stage->isActive() && count($this->activeStages($user->companyId())) <= 1) {
                throw new BadRequestHttpException('At least one production stage must stay active.');
            }

            $stage->configure(
                name: array_key_exists('name', $payload) && $payload['name'] !== null ? $this->requireName($payload['name']) : $stage->getName(),
                reconciliationMode: $this->parseMode($payload['reconciliation_mode'] ?? null) ?? $stage->getReconciliationMode(),
                canRecordQuantity: $payload['can_record_quantity'] ?? $stage->canRecordQuantity(),
                canRecordLoss: $payload['can_record_loss'] ?? $stage->canRecordLoss(),
                isActive: $isActive,
            );
            $this->audit($user, 'production.stage.updated', 'production_stage', $stage->getId(), [
                'before' => $before,
                'after' => $this->serializeStage($stage),
            ]);

            return $this->serializeStage($stage);
        });
    }

    /**
     * @param list<string> $stageIds every stage of the company, in the new order
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function reorderStages(User $user, array $stageIds): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $stageIds): array {
            $stages = $this->allStages($user->companyId());
            $byId = [];
            foreach ($stages as $stage) {
                $byId[$stage->getId()] = $stage;
            }

            if (count($stageIds) !== count($byId) || array_diff(array_keys($byId), $stageIds) !== []) {
                throw new BadRequestHttpException('Send every stage id exactly once, in the new order.');
            }

            // (company_id, sequence) is unique: park every stage on a temporary negative sequence first.
            foreach ($stages as $index => $stage) {
                $stage->moveToSequence(-1 - $index);
            }
            $this->entityManager->flush();

            foreach (array_values($stageIds) as $index => $stageId) {
                $byId[$stageId]->moveToSequence($index + 1);
            }
            $this->entityManager->flush();

            $this->audit($user, 'production.stage.reordered', 'production_stage', null, ['order' => array_values($stageIds)]);

            return ['items' => array_map($this->serializeStage(...), $this->allStages($user->companyId()))];
        });
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listLossReasons(User $user): array
    {
        return $this->unitOfWork->transactional(function () use ($user): array {
            $this->ensureDefaults($user->companyId());

            /** @var list<ProductionLossReason> $reasons */
            $reasons = $this->lossReasonRepository()->findBy(
                ['companyId' => $user->companyId()->toString()],
                ['label' => 'ASC'],
            );

            return ['items' => array_map($this->serializeLossReason(...), $reasons)];
        });
    }

    /**
     * @param array{code?: string|null, label?: string|null} $payload
     *
     * @return array<string, mixed>
     */
    public function createLossReason(User $user, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload): array {
            $companyId = $user->companyId();
            $this->ensureDefaults($companyId);
            $label = $this->requireName($payload['label'] ?? null, 'label');
            $code = $this->normalizeCode(($payload['code'] ?? null) ?: $label);

            if ($this->lossReasonRepository()->findOneBy(['companyId' => $companyId->toString(), 'code' => $code]) !== null) {
                throw new BadRequestHttpException(sprintf('A loss reason with code %s already exists.', $code));
            }

            $reason = new ProductionLossReason(EntityId::generate(), $companyId, $code, $label);
            $this->entityManager->persist($reason);
            $this->audit($user, 'production.loss_reason.created', 'production_loss_reason', $reason->getId(), $this->serializeLossReason($reason));

            return $this->serializeLossReason($reason);
        });
    }

    /**
     * @param array{label?: string|null, is_active?: bool|null} $payload
     *
     * @return array<string, mixed>
     */
    public function updateLossReason(User $user, string $reasonId, array $payload): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $reasonId, $payload): array {
            /** @var ProductionLossReason|null $reason */
            $reason = $this->lossReasonRepository()->findOneBy([
                'id' => $reasonId,
                'companyId' => $user->companyId()->toString(),
            ]);

            if ($reason === null) {
                throw new NotFoundHttpException('Loss reason not found.');
            }

            $before = $this->serializeLossReason($reason);
            $reason->update(
                array_key_exists('label', $payload) && $payload['label'] !== null ? $this->requireName($payload['label'], 'label') : $reason->getLabel(),
                $payload['is_active'] ?? $reason->isActive(),
            );
            $this->audit($user, 'production.loss_reason.updated', 'production_loss_reason', $reason->getId(), [
                'before' => $before,
                'after' => $this->serializeLossReason($reason),
            ]);

            return $this->serializeLossReason($reason);
        });
    }

    /** @return list<ProductionStage> */
    private function allStages(EntityId $companyId): array
    {
        /** @var list<ProductionStage> $stages */
        $stages = $this->stageRepository()->findBy(['companyId' => $companyId->toString()], ['sequence' => 'ASC']);

        return $stages;
    }

    private function findStage(User $user, string $stageId): ProductionStage
    {
        /** @var ProductionStage|null $stage */
        $stage = $this->stageRepository()->findOneBy(['id' => $stageId, 'companyId' => $user->companyId()->toString()]);

        if ($stage === null) {
            throw new NotFoundHttpException('Production stage not found.');
        }

        return $stage;
    }

    private function requireName(?string $value, string $field = 'name'): string
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strlen($value) > 128) {
            throw new BadRequestHttpException(sprintf('%s is required (at most 128 characters).', $field));
        }

        return $value;
    }

    private function parseMode(?string $value): ?ReconciliationMode
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ReconciliationMode::tryFrom(strtoupper($value))
            ?? throw new BadRequestHttpException('reconciliation_mode must be STRICT, FLEXIBLE or CONVERSION.');
    }

    private function normalizeCode(string $value): string
    {
        $code = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value), '_'));

        if ($code === '') {
            throw new BadRequestHttpException('code must contain letters or digits.');
        }

        return substr($code, 0, 32);
    }

    /** @param array<string, mixed> $payload */
    private function audit(User $user, string $action, string $entityType, ?string $entityId, array $payload): void
    {
        $this->auditRecorder->record(
            action: $action,
            payload: $payload,
            companyId: $user->companyId(),
            actorUserId: EntityId::fromString($user->getId()),
            entityType: $entityType,
            entityId: $entityId !== null ? EntityId::fromString($entityId) : null,
            flush: false,
        );
    }

    /** @return array<string, mixed> */
    private function serializeStage(ProductionStage $stage): array
    {
        return [
            'id' => $stage->getId(),
            'sequence' => $stage->getSequence(),
            'name' => $stage->getName(),
            'reconciliation_mode' => $stage->getReconciliationMode()->value,
            'can_record_quantity' => $stage->canRecordQuantity(),
            'can_record_loss' => $stage->canRecordLoss(),
            'is_active' => $stage->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeLossReason(ProductionLossReason $reason): array
    {
        return [
            'id' => $reason->getId(),
            'code' => $reason->getCode(),
            'label' => $reason->getLabel(),
            'is_active' => $reason->isActive(),
        ];
    }

    /** @return \Doctrine\ORM\EntityRepository<ProductionStage> */
    private function stageRepository(): \Doctrine\ORM\EntityRepository
    {
        return $this->entityManager->getRepository(ProductionStage::class);
    }

    /** @return \Doctrine\ORM\EntityRepository<ProductionLossReason> */
    private function lossReasonRepository(): \Doctrine\ORM\EntityRepository
    {
        return $this->entityManager->getRepository(ProductionLossReason::class);
    }
}
