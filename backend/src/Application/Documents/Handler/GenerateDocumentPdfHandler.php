<?php

declare(strict_types=1);

namespace App\Application\Documents\Handler;

use App\Application\Documents\Message\GenerateDocumentPdf;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Documents\DocumentFile;
use App\Infrastructure\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDocumentPdfHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentStorage $documentStorage,
    ) {
    }

    public function __invoke(GenerateDocumentPdf $message): void
    {
        /** @var CommercialDocument|null $document */
        $document = $this->entityManager->getRepository(CommercialDocument::class)->findOneBy([
            'id' => $message->documentId,
            'companyId' => $message->companyId,
        ]);

        if ($document === null) {
            return;
        }

        $version = $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(DocumentFile::class, 'f')
            ->where('f.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();

        $version = ((int) $version) + 1;
        $storageKey = sprintf(
            '%s/documents/%s/v%d.pdf',
            $message->companyId,
            $document->getId(),
            $version,
        );

        $content = $this->renderPdfContent($document);
        $this->documentStorage->store($storageKey, $content, 'application/pdf');

        $file = new DocumentFile(
            EntityId::generate(),
            $document,
            $storageKey,
            'application/pdf',
            $version,
        );
        $this->entityManager->persist($file);
        $this->entityManager->flush();
    }

    private function renderPdfContent(CommercialDocument $document): string
    {
        $lines = [];

        foreach ($document->getLines() as $line) {
            $lines[] = sprintf(
                '%s | %s x %s = %s %s',
                $line->getDescription(),
                $line->getQuantity()->amount(),
                $line->getUnitPrice()->amount(),
                $line->getLineTotal()->amount(),
                $document->getCurrency(),
            );
        }

        return implode("\n", [
            'Tittawin Commercial Document',
            'Type: '.$document->getDocumentType()->value,
            'Number: '.($document->getDocumentNumber() ?? 'DRAFT'),
            'Customer: '.$document->getCustomerDisplayName(),
            'Total: '.$document->getGrandTotal()->amount().' '.$document->getCurrency(),
            '---',
            ...$lines,
        ]);
    }
}
