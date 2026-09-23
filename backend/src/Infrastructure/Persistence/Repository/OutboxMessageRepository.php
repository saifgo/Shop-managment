<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Outbox\OutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

final class OutboxMessageRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(OutboxMessage $message): void
    {
        $this->entityManager->persist($message);
    }

    /**
     * @return list<OutboxMessage>
     */
    public function findUnpublished(int $limit = 100): array
    {
        /** @var list<OutboxMessage> $messages */
        $messages = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(OutboxMessage::class, 'o')
            ->where('o.publishedAt IS NULL')
            ->orderBy('o.occurredAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $messages;
    }
}
