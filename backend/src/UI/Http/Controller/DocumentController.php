<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Documents\DocumentService;
use App\Domain\Documents\DocumentType;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Http\Middleware\IdempotencyKeySubscriber;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Documents')]
final class DocumentController extends AbstractController
{
    public function __construct(private DocumentService $documentService)
    {
    }

    #[Route('/api/documents', name: 'api_documents_list', methods: ['GET'])]
    #[OA\Get(path: '/api/documents', summary: 'List commercial documents of any type', security: [['Bearer' => []]])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_VIEW);

        $type = $request->query->get('type');
        $documentType = null;

        if (is_string($type) && $type !== '') {
            $documentType = DocumentType::tryFrom($type)
                ?? throw new BadRequestHttpException(sprintf('Unknown document type "%s".', $type));
        }

        return $this->json($this->documentService->listDocuments(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            type: $documentType,
            customerId: $request->query->get('customer_id'),
            status: $request->query->get('status'),
        )->toArray());
    }

    #[Route('/api/documents', name: 'api_documents_create', methods: ['POST'])]
    #[OA\Post(path: '/api/documents', summary: 'Create a document manually (invoice, quote, proforma, order, delivery or goods issue note)', security: [['Bearer' => []]])]
    public function create(Request $request, #[MapRequestPayload] CreateManualDocumentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        $document = $this->documentService->createManualDocument($user, [
            'document_type' => $payload->documentType,
            'customer_id' => $payload->customerId,
            'currency' => $payload->currency,
            'order_id' => $payload->orderId,
            'notes' => $payload->notes,
            'due_date' => $payload->dueDate,
            'issue' => $payload->issue,
            'lines' => $payload->lines,
        ], is_string($idempotencyKey) ? $idempotencyKey : null);

        return $this->json($document, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/documents/{id}', name: 'api_documents_get', methods: ['GET'])]
    #[OA\Get(path: '/api/documents/{id}', summary: 'Get a commercial document', security: [['Bearer' => []]])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_VIEW);

        return $this->json($this->documentService->get($user, $id));
    }

    #[Route('/api/documents/{id}/issue', name: 'api_documents_issue', methods: ['POST'])]
    #[OA\Post(path: '/api/documents/{id}/issue', summary: 'Number and post a draft document', security: [['Bearer' => []]])]
    public function issue(string $id, #[MapRequestPayload] IssueDocumentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);

        return $this->json($this->documentService->issueDocument($user, $id, $payload->dueDate));
    }

    #[Route('/api/documents/{id}/cancel', name: 'api_documents_cancel', methods: ['POST'])]
    #[OA\Post(path: '/api/documents/{id}/cancel', summary: 'Cancel a draft document', security: [['Bearer' => []]])]
    public function cancel(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_CANCEL);

        return $this->json($this->documentService->cancelDocument($user, $id));
    }

    #[Route('/api/documents/{id}/pdf', name: 'api_documents_regenerate_pdf', methods: ['POST'])]
    #[OA\Post(path: '/api/documents/{id}/pdf', summary: 'Render a new PDF version with the current company details', security: [['Bearer' => []]])]
    public function regeneratePdf(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);

        return $this->json($this->documentService->regeneratePdf($user, $id));
    }

    #[Route('/api/documents/{id}/share', name: 'api_documents_share', methods: ['POST'])]
    #[OA\Post(path: '/api/documents/{id}/share', summary: 'Create (or keep) the public link to view and download an issued document', security: [['Bearer' => []]])]
    public function share(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);

        return $this->json($this->documentService->share($user, $id));
    }

    #[Route('/api/documents/{id}/share', name: 'api_documents_unshare', methods: ['DELETE'])]
    #[OA\Delete(path: '/api/documents/{id}/share', summary: 'Revoke the public link of a document', security: [['Bearer' => []]])]
    public function unshare(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);

        return $this->json($this->documentService->unshare($user, $id));
    }
}

final readonly class CreateManualDocumentRequest
{
    /**
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        #[SerializedName('document_type')]
        #[Assert\NotBlank]
        public string $documentType,
        #[SerializedName('customer_id')]
        #[Assert\NotBlank]
        public string $customerId,
        /** @var list<array<string, mixed>> */
        #[Assert\Count(min: 1)]
        public array $lines,
        public ?string $currency = null,
        #[SerializedName('order_id')]
        public ?string $orderId = null,
        public ?string $notes = null,
        #[SerializedName('due_date')]
        public ?string $dueDate = null,
        public bool $issue = false,
    ) {
    }
}

final readonly class IssueDocumentRequest
{
    public function __construct(
        /** Only used for invoices; defaults to 30 days after issue. */
        #[SerializedName('due_date')]
        public ?string $dueDate = null,
    ) {
    }
}
