<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Purchasing\PurchasingService;
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
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Purchasing')]
final class PurchasingController extends AbstractController
{
    public function __construct(private PurchasingService $purchasingService)
    {
    }

    #[Route('/api/suppliers', name: 'api_suppliers_list', methods: ['GET'])]
    public function listSuppliers(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_VIEW);

        return $this->json($this->purchasingService->listSuppliers(
            $user,
            max(1, (int) $request->query->get('page', 1)),
            min(100, max(1, (int) $request->query->get('per_page', 20))),
        )->toArray());
    }

    #[Route('/api/suppliers', name: 'api_suppliers_create', methods: ['POST'])]
    public function createSupplier(#[MapRequestPayload] CreateSupplierRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->createSupplier($user, [
            'code' => $payload->code,
            'name' => $payload->name,
            'contact_email' => $payload->contact_email,
            'contact_phone' => $payload->contact_phone,
            'address' => $payload->address,
            'tax_id' => $payload->tax_id,
        ]), 201);
    }

    #[Route('/api/suppliers/{id}', name: 'api_suppliers_get', methods: ['GET'])]
    public function getSupplier(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_VIEW);

        return $this->json($this->purchasingService->getSupplier($user, $id));
    }

    #[Route('/api/suppliers/{id}/products', name: 'api_supplier_products_create', methods: ['POST'])]
    public function addSupplierProduct(string $id, #[MapRequestPayload] CreateSupplierProductRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->addSupplierProduct($user, $id, [
            'variant_id' => $payload->variant_id,
            'purchase_price' => $payload->purchase_price,
            'currency' => $payload->currency,
            'supplier_sku' => $payload->supplier_sku,
            'lead_time_days' => $payload->lead_time_days,
            'minimum_order_qty' => $payload->minimum_order_qty,
        ]), 201);
    }

    #[Route('/api/suppliers/{id}/balance', name: 'api_suppliers_balance', methods: ['GET'])]
    public function supplierBalance(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_VIEW);

        return $this->json($this->purchasingService->supplierBalance($user, $id));
    }

    #[Route('/api/purchase-orders', name: 'api_purchase_orders_list', methods: ['GET'])]
    public function listPurchaseOrders(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_VIEW);

        return $this->json($this->purchasingService->listPurchaseOrders(
            $user,
            max(1, (int) $request->query->get('page', 1)),
            min(100, max(1, (int) $request->query->get('per_page', 20))),
            $request->query->get('supplier_id'),
        )->toArray());
    }

    #[Route('/api/purchase-orders', name: 'api_purchase_orders_create', methods: ['POST'])]
    public function createPurchaseOrder(Request $request, #[MapRequestPayload] CreatePurchaseOrderRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->createPurchaseOrder($user, [
            'supplier_id' => $payload->supplier_id,
            'currency' => $payload->currency,
            'expected_at' => $payload->expected_at,
            'notes' => $payload->notes,
            'items' => $payload->items,
        ], $request->headers->get(IdempotencyKeySubscriber::HEADER_NAME)), 201);
    }

    #[Route('/api/purchase-orders/{id}', name: 'api_purchase_orders_get', methods: ['GET'])]
    public function getPurchaseOrder(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_VIEW);

        return $this->json($this->purchasingService->getPurchaseOrder($user, $id));
    }

    #[Route('/api/purchase-orders/{id}/receive', name: 'api_purchase_orders_receive', methods: ['POST'])]
    public function receivePurchaseOrder(string $id, Request $request, #[MapRequestPayload] ReceivePurchaseOrderRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->receivePurchaseOrder(
            $user,
            $id,
            $payload->lines,
            $payload->notes,
            $request->headers->get(IdempotencyKeySubscriber::HEADER_NAME),
        ), 201);
    }

    #[Route('/api/supplier-invoices', name: 'api_supplier_invoices_create', methods: ['POST'])]
    public function createSupplierInvoice(#[MapRequestPayload] CreateSupplierInvoiceRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->createSupplierInvoice($user, [
            'supplier_id' => $payload->supplier_id,
            'invoice_number' => $payload->invoice_number,
            'total_amount' => $payload->total_amount,
            'currency' => $payload->currency,
            'issued_at' => $payload->issued_at,
            'purchase_order_id' => $payload->purchase_order_id,
            'due_date' => $payload->due_date,
            'notes' => $payload->notes,
        ]), 201);
    }

    #[Route('/api/supplier-payments', name: 'api_supplier_payments_create', methods: ['POST'])]
    public function recordSupplierPayment(Request $request, #[MapRequestPayload] RecordSupplierPaymentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->recordSupplierPayment($user, [
            'supplier_id' => $payload->supplier_id,
            'amount' => $payload->amount,
            'currency' => $payload->currency,
            'method' => $payload->method,
            'payment_date' => $payload->payment_date,
            'notes' => $payload->notes,
        ], $request->headers->get(IdempotencyKeySubscriber::HEADER_NAME)), 201);
    }

    #[Route('/api/supplier-payments/{id}/allocate', name: 'api_supplier_payments_allocate', methods: ['POST'])]
    public function allocateSupplierPayment(string $id, #[MapRequestPayload] AllocateSupplierPaymentRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PURCHASING_MANAGE);

        return $this->json($this->purchasingService->allocateSupplierPayment($user, $id, $payload->allocations));
    }
}

final readonly class CreateSupplierRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $code,
        #[Assert\NotBlank]
        public string $name,
        public ?string $contact_email = null,
        public ?string $contact_phone = null,
        public ?string $address = null,
        public ?string $tax_id = null,
    ) {
    }
}

final readonly class CreateSupplierProductRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $variant_id,
        #[Assert\NotBlank]
        public string $purchase_price,
        #[Assert\NotBlank]
        public string $currency,
        public ?string $supplier_sku = null,
        public ?int $lead_time_days = null,
        public ?string $minimum_order_qty = null,
    ) {
    }
}

final readonly class CreatePurchaseOrderRequest
{
    /** @param list<array{variant_id: string, quantity: string, unit_price: string}> $items */
    public function __construct(
        #[Assert\NotBlank]
        public string $supplier_id,
        #[Assert\NotBlank]
        public string $currency,
        #[Assert\Count(min: 1)]
        public array $items,
        public ?string $expected_at = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class ReceivePurchaseOrderRequest
{
    /** @param list<array{purchase_order_item_id: string, quantity: string}> $lines */
    public function __construct(
        #[Assert\Count(min: 1)]
        public array $lines,
        public ?string $notes = null,
    ) {
    }
}

final readonly class CreateSupplierInvoiceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $supplier_id,
        #[Assert\NotBlank]
        public string $invoice_number,
        #[Assert\NotBlank]
        public string $total_amount,
        #[Assert\NotBlank]
        public string $currency,
        #[Assert\NotBlank]
        public string $issued_at,
        public ?string $purchase_order_id = null,
        public ?string $due_date = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class RecordSupplierPaymentRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $supplier_id,
        #[Assert\NotBlank]
        public string $amount,
        #[Assert\NotBlank]
        public string $currency,
        #[Assert\NotBlank]
        public string $method,
        #[Assert\NotBlank]
        public string $payment_date,
        public ?string $notes = null,
    ) {
    }
}

final readonly class AllocateSupplierPaymentRequest
{
    /** @param list<array{invoice_id: string, amount: string}> $allocations */
    public function __construct(
        #[Assert\Count(min: 1)]
        public array $allocations,
    ) {
    }
}
