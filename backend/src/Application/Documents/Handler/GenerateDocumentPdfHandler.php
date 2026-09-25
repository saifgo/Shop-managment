<?php

declare(strict_types=1);

namespace App\Application\Documents\Handler;

use App\Application\Documents\DocumentPdfService;
use App\Application\Documents\Message\GenerateDocumentPdf;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDocumentPdfHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentPdfService $documentPdfService,
    ) {
    }

    public function __invoke(GenerateDocumentPdf $message): void
    {
        /** @var CommercialDocument|null $document */
        $document = $this->entityManager->getRepository(CommercialDocument::class)->findOneBy([
            'id' => $message->documentId,
            'companyId' => $message->companyId,
        ]);

        // The message is sent before the issuing transaction commits, so the worker may
        // still see a draft (or nothing). Skipping is safe: downloads generate on demand.
        if ($document === null || !$document->isPosted()) {
            return;
        }

        $this->documentPdfService->generate($document);
    }
}
