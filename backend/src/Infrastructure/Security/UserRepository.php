<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;

final class UserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findActiveByEmail(string $email): ?User
    {
        /** @var User|null $user */
        $user = $this->entityManager->createQueryBuilder()
            ->select('u', 'r', 'p')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('r.permissions', 'p')
            ->where('u.email = :email')
            ->andWhere('u.isActive = true')
            ->setParameter('email', strtolower($email))
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    public function findActiveById(string $id): ?User
    {
        /** @var User|null $user */
        $user = $this->entityManager->createQueryBuilder()
            ->select('u', 'r', 'p')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('r.permissions', 'p')
            ->where('u.id = :id')
            ->andWhere('u.isActive = true')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }
}
