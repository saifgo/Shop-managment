<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Documents\DocumentPdfService;
use App\Application\Documents\DocumentService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Share links: anyone holding a document's share token can view and download it
 * without signing in. Revoking the link (DELETE /api/documents/{id}/share) cuts access.
 */
#[OA\Tag(name: 'Shared documents')]
final class PublicDocumentController extends AbstractController
{
    private const TOKEN = '[A-Za-z0-9_-]{16,64}';

    /** Shared documents must not be cached by proxies or indexed, so revoking takes effect at once. */
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(
        private DocumentService $documentService,
        private DocumentPdfService $documentPdfService,
    ) {
    }

    #[Route('/api/public/documents/{token}', name: 'api_public_documents_show', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    #[OA\Get(path: '/api/public/documents/{token}', summary: 'Summary of a shared document')]
    public function show(string $token): JsonResponse
    {
        return $this->json($this->documentService->sharedSummary($token), headers: self::PRIVATE_HEADERS);
    }

    #[Route('/api/public/documents/{token}/pdf', name: 'api_public_documents_pdf', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    #[OA\Get(path: '/api/public/documents/{token}/pdf', summary: 'PDF of a shared document; add ?download=1 to save it instead of opening it')]
    public function pdf(string $token, Request $request): Response
    {
        $document = $this->documentService->findShared($token);
        $disposition = $request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;

        return new Response($this->documentPdfService->latest($document), Response::HTTP_OK, [
            'Content-Type' => DocumentPdfService::MIME_TYPE,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, DocumentPdfService::filename($document)),
            ...self::PRIVATE_HEADERS,
        ]);
    }

    #[Route('/api/public/documents/{token}/preview', name: 'api_public_documents_preview', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    #[OA\Get(path: '/api/public/documents/{token}/preview', summary: 'HTML rendering of a shared document, for embedding in the share page')]
    public function preview(string $token): Response
    {
        $document = $this->documentService->findShared($token);

        return new Response($this->documentPdfService->previewHtml($document), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Static markup only: no scripts, nothing loaded from elsewhere, embeddable by the share page.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:",
            'X-Content-Type-Options' => 'nosniff',
            ...self::PRIVATE_HEADERS,
        ]);
    }
}
