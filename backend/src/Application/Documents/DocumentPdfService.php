<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Settings\CompanyProfileService;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Documents\DocumentRenderer;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Documents\DocumentFile;
use App\Infrastructure\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generated PDFs are archived as numbered versions in DocumentStorage. Each generation
 * uses the company profile as it is at that moment; regenerating adds a new version.
 */
final class DocumentPdfService
{
    public const MIME_TYPE = 'application/pdf';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentStorage $storage,
        private DocumentRenderer $renderer,
        private CompanyProfileService $companyProfileService,
    ) {
    }

    /** Renders the document and stores it as its newest PDF version; returns the PDF bytes. */
    public function generate(CommercialDocument $document): string
    {
        $content = $this->renderer->renderPdf($document, $this->companyProfileService->profile($document->companyId()));

        $version = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(DocumentFile::class, 'f')
            ->where('f.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult() + 1;
        $storageKey = sprintf('%s/documents/%s/v%d.pdf', $document->companyId()->toString(), $document->getId(), $version);

        $this->storage->store($storageKey, $content, self::MIME_TYPE);
        $this->entityManager->persist(new DocumentFile(EntityId::generate(), $document, $storageKey, self::MIME_TYPE, $version));
        $this->entityManager->flush();

        return $content;
    }

    /**
     * The newest stored PDF, generated on the spot when there is none yet (the async
     * worker has not run) or when the stored file is not a real PDF — early versions
     * wrote plain text under a .pdf name.
     */
    public function latest(CommercialDocument $document): string
    {
        if (!$document->isPosted()) {
            // Drafts still change, so their PDF is rendered fresh and not archived.
            return $this->renderer->renderPdf($document, $this->companyProfileService->profile($document->companyId()));
        }

        /** @var DocumentFile|null $file */
        $file = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(DocumentFile::class, 'f')
            ->where('f.document = :document')
            ->setParameter('document', $document)
            ->orderBy('f.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $content = $file !== null ? $this->storage->read($file->getStorageKey()) : null;

        if ($content !== null && str_starts_with($content, '%PDF-')) {
            return $content;
        }

        return $this->generate($document);
    }

    /** The document as an HTML page, drawn with the same template as the PDF. */
    public function previewHtml(CommercialDocument $document): string
    {
        return $this->renderer->renderHtml($document, $this->companyProfileService->profile($document->companyId()));
    }

    /** e.g. "Facture_INV-2026-000001.pdf". */
    public static function filename(CommercialDocument $document): string
    {
        $name = $document->getDocumentType()->printedTitle().'_'.($document->getDocumentNumber() ?? $document->getId());

        // Plain ASCII keeps the Content-Disposition header valid in every browser.
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name).'.pdf';
    }
}
