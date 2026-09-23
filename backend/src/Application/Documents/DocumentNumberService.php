<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\DocumentType;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Documents\DocumentSequence;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class DocumentNumberService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextNumber(EntityId $companyId, DocumentType $type, ?int $fiscalYear = null): array
    {
        $year = $fiscalYear ?? (int) date('Y');
        $prefix = $type->numberPrefix();

        $qb = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(DocumentSequence::class, 's')
            ->where('s.companyId = :companyId')
            ->andWhere('s.documentType = :type')
            ->andWhere('s.fiscalYear = :year')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('type', $type)
            ->setParameter('year', $year)
            ->getQuery();

        /** @var DocumentSequence|null $sequence */
        $sequence = $qb->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        if ($sequence === null) {
            $sequence = new DocumentSequence(EntityId::generate(), $companyId, $type, $year);
            $this->entityManager->persist($sequence);
        }

        $next = $sequence->nextNumber();
        $documentNumber = sprintf('%s-%d-%04d', $prefix, $year, $next);

        return ['number' => $documentNumber, 'fiscal_year' => $year, 'sequence' => $next];
    }
}
