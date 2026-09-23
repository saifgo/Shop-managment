<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\ORM\EntityManagerInterface;

final class UnitOfWork
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function transactional(callable $callback): mixed
    {
        return $this->entityManager->wrapInTransaction($callback);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function clear(): void
    {
        $this->entityManager->clear();
    }
}
