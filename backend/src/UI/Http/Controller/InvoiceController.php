<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Documents\DocumentService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Http\Middleware\IdempotencyKeySubscriber;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Invoices')]
final class InvoiceController extends AbstractController
{
    public function __construct(private DocumentService $documentService)
    {
    }

    #[Route('/api/invoices', name: 'api_invoices_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyInvoiceAccess($user);

        return $this->json($this->documentService->listInvoices(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            customerId: $request->query->get('customer_id'),
        )->toArray());
    }

    #[Route('/api/invoices', name: 'api_invoices_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] CreateInvoiceRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        $invoice = $this->documentService->createInvoice($user, [
            'delivery_id' => $payload->deliveryId,
            'order_id' => $payload->orderId,
            'notes' => $payload->notes,
        ], is_string($idempotencyKey) ? $idempotencyKey : null);

        return $this->json($invoice, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/invoices/{id}', name: 'api_invoices_get', methods: ['GET'])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyInvoiceAccess($user);

        return $this->json($this->documentService->get($user, $id));
    }

    #[Route('/api/invoices/{id}/issue', name: 'api_invoices_issue', methods: ['POST'])]
    public function issue(string $id, #[MapRequestPayload] IssueInvoiceRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);

        return $this->json($this->documentService->issueInvoice($user, $id, $payload->dueDate));
    }

    #[Route('/api/invoices/{id}/cancel', name: 'api_invoices_cancel', methods: ['POST'])]
    public function cancel(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_CANCEL);

        return $this->json($this->documentService->cancelInvoice($user, $id));
    }

    #[Route('/api/invoices/{id}/credit-note', name: 'api_invoices_credit_note', methods: ['POST'])]
    public function creditNote(string $id, Request $request, #[MapRequestPayload] CreditNoteRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        return $this->json(
            $this->documentService->createCreditNote($user, $id, $payload->reason, is_string($idempotencyKey) ? $idempotencyKey : null),
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/api/documents/{id}/download', name: 'api_documents_download', methods: ['GET'])]
    #[OA\Get(path: '/api/documents/{id}/download', summary: 'Download the document PDF (generated on demand when missing)', security: [['Bearer' => []]])]
    public function download(string $id, #[CurrentUser] User $user): Response
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);
        } else {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_VIEW);
        }

        $file = $this->documentService->downloadPdf($user, $id);

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['filename']),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function denyInvoiceAccess(User $user): void
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);

            return;
        }

        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_VIEW);
    }
}

final readonly class CreateInvoiceRequest
{
    public function __construct(
        #[SerializedName('delivery_id')]
        public ?string $deliveryId = null,
        #[SerializedName('order_id')]
        public ?string $orderId = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class IssueInvoiceRequest
{
    public function __construct(
        #[SerializedName('due_date')]
        public ?string $dueDate = null,
    ) {
    }
}

final readonly class CreditNoteRequest
{
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}
