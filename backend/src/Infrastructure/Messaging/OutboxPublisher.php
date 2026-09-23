<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Persistence\Repository\OutboxMessageRepository;
use App\Infrastructure\Persistence\UnitOfWork;
use Psr\Log\LoggerInterface;

/**
 * Stub publisher for outbox messages. Phase 1 logs unpublished messages;
 * Messenger dispatch will be wired in a later phase.
 */
final class OutboxPublisher
{
    public function __construct(
        private OutboxMessageRepository $repository,
        private UnitOfWork $unitOfWork,
        private LoggerInterface $logger,
    ) {
    }

    public function publishPending(int $batchSize = 50): int
    {
        $messages = $this->repository->findUnpublished($batchSize);
        $published = 0;

        foreach ($messages as $message) {
            $this->logger->info('Outbox message pending publish (stub)', [
                'id' => $message->getId(),
                'event_type' => $message->getEventType(),
            ]);
            $message->incrementAttempts();
            $message->markPublished();
            ++$published;
        }

        if ($published > 0) {
            $this->unitOfWork->flush();
        }

        return $published;
    }
}
