<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Documents\DocumentService;
use App\Application\Payments\PaymentService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Http\Middleware\IdempotencyKeySubscriber;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Payments')]
final class PaymentController extends AbstractController
{
    public function __construct(
        private PaymentService $paymentService,
        private DocumentService $documentService,
    ) {
    }

    #[Route('/api/payments', name: 'api_payments_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] CreatePaymentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PAYMENTS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        $payment = $this->paymentService->record($user, [
            'customer_id' => $payload->customerId,
            'amount' => $payload->amount,
            'currency' => $payload->currency,
            'method' => $payload->method,
            'payment_date' => $payload->paymentDate,
            'notes' => $payload->notes,
        ], is_string($idempotencyKey) ? $idempotencyKey : null);

        return $this->json($payment, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/payments/{id}/allocate', name: 'api_payments_allocate', methods: ['POST'])]
    public function allocate(string $id, #[MapRequestPayload] AllocatePaymentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PAYMENTS_MANAGE);

        return $this->json($this->paymentService->allocate($user, $id, $payload->allocations));
    }

    #[Route('/api/payments/{id}', name: 'api_payments_get', methods: ['GET'])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PAYMENTS_VIEW);

        return $this->json($this->paymentService->get($user, $id));
    }

    #[Route('/api/deliveries/{id}/documents/delivery-note', name: 'api_deliveries_delivery_note', methods: ['POST'])]
    public function deliveryNote(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::DOCUMENTS_MANAGE);
        $idempotencyKey = $request->attributes->get(IdempotencyKeySubscriber::REQUEST_ATTRIBUTE);

        return $this->json(
            $this->documentService->createDeliveryNote($user, $id, is_string($idempotencyKey) ? $idempotencyKey : null),
            JsonResponse::HTTP_CREATED,
        );
    }
}

final readonly class CreatePaymentRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('customer_id')]
        public string $customerId,
        #[Assert\NotBlank]
        public string $amount,
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        public string $currency,
        #[Assert\Choice(choices: ['CASH', 'BANK_TRANSFER', 'CHECK', 'CARD'])]
        public string $method,
        #[Assert\NotBlank]
        #[SerializedName('payment_date')]
        public string $paymentDate,
        public ?string $notes = null,
    ) {
    }
}

final readonly class AllocatePaymentRequest
{
    /** @param list<array{invoice_id: string, amount: string}> $allocations */
    public function __construct(
        #[Assert\Count(min: 1)]
        public array $allocations,
    ) {
    }
}
