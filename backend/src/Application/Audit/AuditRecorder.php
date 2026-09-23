<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Audit\AuditEvent;
use App\Infrastructure\Persistence\Repository\AuditEventRepository;
use App\Infrastructure\Persistence\UnitOfWork;

final class AuditRecorder
{
    public function __construct(
        private AuditEventRepository $repository,
        private UnitOfWork $unitOfWork,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(
        string $action,
        array $payload = [],
        ?EntityId $companyId = null,
        ?EntityId $actorUserId = null,
        ?string $entityType = null,
        ?EntityId $entityId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
        bool $flush = true,
    ): AuditEvent {
        $event = new AuditEvent(
            id: EntityId::generate(),
            action: $action,
            payload: $payload,
            companyId: $companyId,
            actorUserId: $actorUserId,
            entityType: $entityType,
            entityId: $entityId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            correlationId: $correlationId,
        );

        $this->repository->save($event);

        if ($flush) {
            $this->unitOfWork->flush();
        }

        return $event;
    }
}
