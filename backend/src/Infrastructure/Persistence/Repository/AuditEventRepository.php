<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Audit\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;

final class AuditEventRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(AuditEvent $event): void
    {
        $this->entityManager->persist($event);
    }
}
