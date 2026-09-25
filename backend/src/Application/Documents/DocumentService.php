<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Catalog\PricingService;
use App\Application\Documents\Message\GenerateDocumentPdf;
use App\Application\Settings\CompanyProfileService;
use App\Application\Settings\TaxSettingsService;
use App\Application\Shared\PaginatedResult;
use App\Domain\Documents\DocumentRelationType;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Documents\DocumentLine;
use App\Infrastructure\Persistence\Entity\Documents\DocumentRelation;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Sales\Delivery;
use App\Infrastructure\Persistence\Entity\Sales\DeliveryLine;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

final class DocumentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private DocumentNumberService $documentNumberService,
        private DocumentSnapshotBuilder $snapshotBuilder,
        private DocumentPdfService $documentPdfService,
        private MessageBusInterface $messageBus,
        private PricingService $pricingService,
        private TaxSettingsService $taxSettingsService,
        private CompanyProfileService $companyProfileService,
    ) {
    }

    /** @return array<string, mixed> */
    public function createSalesOrderDocument(User $user, string $orderId, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $orderId, $idempotencyKey): array {
            $existing = $this->findByIdempotency($user, $idempotencyKey);

            if ($existing !== null) {
                return $this->snapshotBuilder->serializeDocument($existing);
            }

            $order = $this->findOrder($user, $orderId);
            $document = $this->buildDocumentFromOrder(
                $user,
                $order,
                DocumentType::SalesOrder,
                DocumentStatus::Draft->value,
                $idempotencyKey,
            );
            $this->entityManager->persist($document);
            $this->dispatchPdfGeneration($document);

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function createDeliveryNote(User $user, string $deliveryId, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $deliveryId, $idempotencyKey): array {
            $existing = $this->findByIdempotency($user, $idempotencyKey);

            if ($existing !== null) {
                return $this->snapshotBuilder->serializeDocument($existing);
            }

            $delivery = $this->findDelivery($user, $deliveryId);
            $order = $delivery->getOrder();
            $snapshot = $this->snapshotBuilder->customerSnapshot($order->getCustomer());
            $linePayloads = $this->buildLinesFromDelivery($delivery, $order->getCurrency());
            $totals = DocumentSnapshotBuilder::totalsFromLines($linePayloads, $order->getCurrency());

            $document = new CommercialDocument(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                documentType: DocumentType::DeliveryNote,
                status: DocumentStatus::Draft->value,
                customer: $order->getCustomer(),
                customerDisplayName: $snapshot['display_name'],
                customerLegalName: $snapshot['legal_name'],
                customerTaxId: $snapshot['tax_id'],
                customerVatNumber: $snapshot['vat_number'],
                billingAddress: $snapshot['billing_address'],
                shippingAddress: $snapshot['shipping_address'],
                currency: $order->getCurrency(),
                subtotal: $totals['subtotal'],
                taxTotal: $totals['tax_total'],
                discountTotal: $totals['discount_total'],
                grandTotal: $totals['grand_total'],
                order: $order,
                delivery: $delivery,
                idempotencyKey: $idempotencyKey,
                createdBy: EntityId::fromString($user->getId()),
            );

            $this->persistDocumentLines($document, $linePayloads);
            $this->assignAndPost($document, $user);
            $this->entityManager->persist($document);
            $this->linkToOrderDocumentIfExists($order, $document);
            $this->dispatchPdfGeneration($document);

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /**
     * @param array{delivery_id?: string, order_id?: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function createInvoice(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            $existing = $this->findByIdempotency($user, $idempotencyKey);

            if ($existing !== null) {
                return $this->snapshotBuilder->serializeDocument($existing);
            }

            if (isset($payload['delivery_id'])) {
                $delivery = $this->findDelivery($user, $payload['delivery_id']);
                $this->assertDeliveryNotInvoiced($delivery);
                $orderRef = $delivery->getOrder();
                $linePayloads = $this->buildLinesFromDelivery($delivery, $orderRef->getCurrency());
                $customer = $orderRef->getCustomer();
                $currency = $orderRef->getCurrency();
                $deliveryRef = $delivery;
            } elseif (isset($payload['order_id'])) {
                $orderRef = $this->findOrder($user, $payload['order_id']);
                $linePayloads = $this->buildLinesFromOrder($orderRef);
                $customer = $orderRef->getCustomer();
                $currency = $orderRef->getCurrency();
                $deliveryRef = null;
            } else {
                throw new BadRequestHttpException('delivery_id or order_id is required.');
            }

            $snapshot = $this->snapshotBuilder->customerSnapshot($customer);
            $totals = DocumentSnapshotBuilder::totalsFromLines($linePayloads, $currency);
            $stampDuty = $this->companyProfileService->stampDutyFor($user->companyId(), $currency);

            $document = new CommercialDocument(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                documentType: DocumentType::Invoice,
                status: InvoiceStatus::Draft->value,
                customer: $customer,
                customerDisplayName: $snapshot['display_name'],
                customerLegalName: $snapshot['legal_name'],
                customerTaxId: $snapshot['tax_id'],
                customerVatNumber: $snapshot['vat_number'],
                billingAddress: $snapshot['billing_address'],
                shippingAddress: $snapshot['shipping_address'],
                currency: $currency,
                subtotal: $totals['subtotal'],
                taxTotal: $totals['tax_total'],
                discountTotal: $totals['discount_total'],
                grandTotal: $totals['grand_total']->add($stampDuty),
                order: $orderRef,
                delivery: $deliveryRef,
                notes: $payload['notes'] ?? null,
                idempotencyKey: $idempotencyKey,
                createdBy: EntityId::fromString($user->getId()),
                stampDuty: $stampDuty,
            );

            $this->persistDocumentLines($document, $linePayloads);
            $this->entityManager->persist($document);

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function issueInvoice(User $user, string $invoiceId, ?string $dueDate = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $invoiceId, $dueDate): array {
            $document = $this->findInvoice($user, $invoiceId);

            if ($document->isPosted()) {
                return $this->snapshotBuilder->serializeDocument($document);
            }

            $this->postDraft($document, $user, $dueDate);

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function cancelInvoice(User $user, string $invoiceId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $invoiceId): array {
            $document = $this->findInvoice($user, $invoiceId);

            if ($document->isPosted()) {
                throw new BadRequestHttpException('Posted invoices cannot be cancelled; issue a credit note.');
            }

            $document->cancel();

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function createCreditNote(User $user, string $invoiceId, ?string $reason = null, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $invoiceId, $reason, $idempotencyKey): array {
            $existing = $this->findByIdempotency($user, $idempotencyKey);

            if ($existing !== null) {
                return $this->snapshotBuilder->serializeDocument($existing);
            }

            $invoice = $this->findInvoice($user, $invoiceId);

            if (!$invoice->isPosted()) {
                throw new BadRequestHttpException('Credit notes can only be created for issued invoices.');
            }

            if ($invoice->getStatus() === InvoiceStatus::Credited->value) {
                throw new BadRequestHttpException('Invoice already credited.');
            }

            $linePayloads = [];

            foreach ($invoice->getLines() as $line) {
                $linePayloads[] = [
                    'source_line_id' => $line->getSourceLineId(),
                    'description' => $line->getDescription(),
                    'sku' => $line->getSku(),
                    'quantity' => $line->getQuantity(),
                    'unit_price' => $line->getUnitPrice(),
                    'tax_rate' => $line->getTaxRate(),
                    'discount_amount' => Money::zero($invoice->getCurrency()),
                    'line_subtotal' => $line->getLineSubtotal(),
                    'line_tax' => $line->getLineTax(),
                    'line_total' => $line->getLineTotal(),
                ];
            }

            $document = new CommercialDocument(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                documentType: DocumentType::CreditNote,
                status: DocumentStatus::Draft->value,
                customer: $invoice->getCustomer(),
                customerDisplayName: $invoice->getCustomerDisplayName(),
                customerLegalName: $invoice->getCustomerLegalName(),
                customerTaxId: $invoice->getCustomerTaxId(),
                customerVatNumber: $invoice->getCustomerVatNumber(),
                billingAddress: $invoice->getBillingAddress(),
                shippingAddress: $invoice->getShippingAddress(),
                currency: $invoice->getCurrency(),
                subtotal: $invoice->getSubtotal(),
                taxTotal: $invoice->getTaxTotal(),
                discountTotal: Money::zero($invoice->getCurrency()),
                grandTotal: $invoice->getGrandTotal(),
                order: $invoice->getOrder(),
                delivery: $invoice->getDelivery(),
                sourceDocument: $invoice,
                notes: $reason,
                idempotencyKey: $idempotencyKey,
                createdBy: EntityId::fromString($user->getId()),
                stampDuty: $invoice->getStampDuty(),
            );

            $this->persistDocumentLines($document, $linePayloads);
            $this->assignAndPost($document, $user);
            $invoice->setStatus(InvoiceStatus::Credited->value);
            $this->entityManager->persist($document);
            $this->linkDocuments($invoice, $document, DocumentRelationType::CreditFor);
            $this->dispatchPdfGeneration($document);

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /**
     * Creates a document from hand-entered lines instead of an order or delivery.
     * Lines either reference a catalog variant (description, SKU and customer price
     * are filled in when omitted) or are free-text lines with an explicit price.
     *
     * @param array{
     *     document_type: string,
     *     customer_id: string,
     *     currency?: string|null,
     *     order_id?: string|null,
     *     notes?: string|null,
     *     due_date?: string|null,
     *     issue?: bool,
     *     lines: list<array<string, mixed>>,
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function createManualDocument(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            $existing = $this->findByIdempotency($user, $idempotencyKey);

            if ($existing !== null) {
                return $this->snapshotBuilder->serializeDocument($existing);
            }

            $type = DocumentType::tryFrom($payload['document_type']);

            if ($type === null || !in_array($type, DocumentType::manuallyCreatable(), true)) {
                throw new BadRequestHttpException(sprintf('Document type "%s" cannot be created manually.', $payload['document_type']));
            }

            $customer = $this->findCustomer($user, $payload['customer_id']);
            $currency = strtoupper($payload['currency'] ?? 'TND');

            if (strlen($currency) !== 3) {
                throw new BadRequestHttpException('currency must be a 3-letter code.');
            }

            $order = null;

            if (isset($payload['order_id'])) {
                $order = $this->findOrder($user, $payload['order_id']);

                if ($order->getCustomer()->getId() !== $customer->getId()) {
                    throw new BadRequestHttpException('The linked order belongs to a different customer.');
                }
            }

            if ($payload['lines'] === []) {
                throw new BadRequestHttpException('At least one line is required.');
            }

            $linePayloads = [];

            foreach ($payload['lines'] as $index => $line) {
                $linePayloads[] = $this->buildManualLine($user, $customer, $currency, $line, $index);
            }

            $snapshot = $this->snapshotBuilder->customerSnapshot($customer);
            $totals = DocumentSnapshotBuilder::totalsFromLines($linePayloads, $currency);
            $stampDuty = $type === DocumentType::Invoice
                ? $this->companyProfileService->stampDutyFor($user->companyId(), $currency)
                : Money::zero($currency);

            $document = new CommercialDocument(
                id: EntityId::generate(),
                companyId: $user->companyId(),
                documentType: $type,
                status: $type === DocumentType::Invoice ? InvoiceStatus::Draft->value : DocumentStatus::Draft->value,
                customer: $customer,
                customerDisplayName: $snapshot['display_name'],
                customerLegalName: $snapshot['legal_name'],
                customerTaxId: $snapshot['tax_id'],
                customerVatNumber: $snapshot['vat_number'],
                billingAddress: $snapshot['billing_address'],
                shippingAddress: $snapshot['shipping_address'],
                currency: $currency,
                subtotal: $totals['subtotal'],
                taxTotal: $totals['tax_total'],
                discountTotal: $totals['discount_total'],
                grandTotal: $totals['grand_total']->add($stampDuty),
                order: $order,
                notes: $payload['notes'] ?? null,
                idempotencyKey: $idempotencyKey,
                createdBy: EntityId::fromString($user->getId()),
                stampDuty: $stampDuty,
            );

            $this->persistDocumentLines($document, $linePayloads);
            $this->entityManager->persist($document);

            if ($payload['issue'] ?? false) {
                $this->postDraft($document, $user, $payload['due_date'] ?? null);
            }

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function issueDocument(User $user, string $documentId, ?string $dueDate = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $documentId, $dueDate): array {
            $document = $this->findDocument($user, $documentId);

            if (!$document->isPosted()) {
                $this->postDraft($document, $user, $dueDate);
            }

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function cancelDocument(User $user, string $documentId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $documentId): array {
            $document = $this->findDocument($user, $documentId);

            if ($document->isPosted()) {
                throw new BadRequestHttpException($document->getDocumentType() === DocumentType::Invoice
                    ? 'Posted invoices cannot be cancelled; issue a credit note.'
                    : 'Posted documents cannot be cancelled.');
            }

            $document->cancel();

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $documentId): array
    {
        return $this->snapshotBuilder->serializeDocument($this->findDocument($user, $documentId));
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function listInvoices(User $user, int $page, int $perPage, ?string $customerId = null): PaginatedResult
    {
        return $this->listDocuments($user, $page, $perPage, DocumentType::Invoice, $customerId);
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function listDocuments(
        User $user,
        int $page,
        int $perPage,
        ?DocumentType $type = null,
        ?string $customerId = null,
        ?string $status = null,
    ): PaginatedResult {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('d.createdAt', 'DESC');

        if ($type !== null) {
            $qb->andWhere('d.documentType = :type')->setParameter('type', $type);
        }

        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        if ($customerId !== null) {
            $qb->andWhere('d.customer = :customer')->setParameter('customer', $customerId);
        }

        if ($user->isPortalUser()) {
            $portalCustomer = $this->resolvePortalCustomer($user);
            $qb->andWhere('d.customer = :customer')->setParameter('customer', $portalCustomer);
            $qb->andWhere('d.isPosted = true');
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);
        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $items = [];

        foreach ($paginator as $document) {
            if ($document instanceof CommercialDocument) {
                $items[] = $this->snapshotBuilder->serializeDocument($document);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /** @return array{content: string, mime_type: string, filename: string} */
    public function downloadPdf(User $user, string $documentId): array
    {
        $document = $this->findDocument($user, $documentId);

        if ($user->isPortalUser() && !$document->isPosted()) {
            throw new NotFoundHttpException('Document not found.');
        }

        return [
            'content' => $this->documentPdfService->latest($document),
            'mime_type' => DocumentPdfService::MIME_TYPE,
            'filename' => DocumentPdfService::filename($document),
        ];
    }

    /**
     * Renders a new PDF version, e.g. after the company details printed on documents changed.
     *
     * @return array<string, mixed>
     */
    public function regeneratePdf(User $user, string $documentId): array
    {
        $document = $this->findDocument($user, $documentId);

        if (!$document->isPosted()) {
            throw new BadRequestHttpException('Drafts have no stored PDF; issue the document first.');
        }

        $this->documentPdfService->generate($document);

        return $this->snapshotBuilder->serializeDocument($document);
    }

    /**
     * Turns on the public link (/share/{token}) that lets anyone holding it view and
     * download the document without signing in. Sharing an already shared document
     * keeps its current link.
     *
     * @return array<string, mixed>
     */
    public function share(User $user, string $documentId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $documentId): array {
            $document = $this->findDocument($user, $documentId);

            if (!$document->isPosted()) {
                throw new BadRequestHttpException('Issue the document before sharing it.');
            }

            if ($document->getShareToken() === null) {
                // 24 random bytes = 192 bits, URL-safe base64 without padding (32 characters).
                $document->enableSharing(rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='));
            }

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /**
     * Revokes the public link; sharing again later creates a new one.
     *
     * @return array<string, mixed>
     */
    public function unshare(User $user, string $documentId): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $documentId): array {
            $document = $this->findDocument($user, $documentId);
            $document->disableSharing();

            return $this->snapshotBuilder->serializeDocument($document);
        });
    }

    /** The document behind a public share link; the token itself is the credential. */
    public function findShared(string $token): CommercialDocument
    {
        /** @var CommercialDocument|null $document */
        $document = $token === '' ? null : $this->entityManager->getRepository(CommercialDocument::class)->findOneBy([
            'shareToken' => $token,
        ]);

        if ($document === null || !$document->isPosted()) {
            throw new NotFoundHttpException('This link is invalid or has been revoked.');
        }

        return $document;
    }

    /**
     * What the public share page shows around the document preview.
     *
     * @return array<string, mixed>
     */
    public function sharedSummary(string $token): array
    {
        $document = $this->findShared($token);
        $currency = $document->getCurrency();

        return [
            'document_type' => $document->getDocumentType()->value,
            'title' => $document->getDocumentType()->printedTitle(),
            'document_number' => $document->getDocumentNumber(),
            'issued_at' => $document->getIssuedAt()?->format(DATE_ATOM),
            'due_date' => $document->getDueDate()?->format('Y-m-d'),
            'customer_display_name' => $document->getCustomerDisplayName(),
            'company_name' => $this->companyProfileService->profile($document->companyId())->name,
            'grand_total' => ['amount' => $document->getGrandTotal()->amount(), 'currency' => $currency],
            'filename' => DocumentPdfService::filename($document),
        ];
    }

    private function buildDocumentFromOrder(
        User $user,
        Order $order,
        DocumentType $type,
        string $status,
        ?string $idempotencyKey,
    ): CommercialDocument {
        $snapshot = $this->snapshotBuilder->customerSnapshot($order->getCustomer());
        $linePayloads = $this->buildLinesFromOrder($order);
        $totals = DocumentSnapshotBuilder::totalsFromLines($linePayloads, $order->getCurrency());

        $document = new CommercialDocument(
            id: EntityId::generate(),
            companyId: $user->companyId(),
            documentType: $type,
            status: $status,
            customer: $order->getCustomer(),
            customerDisplayName: $snapshot['display_name'],
            customerLegalName: $snapshot['legal_name'],
            customerTaxId: $snapshot['tax_id'],
            customerVatNumber: $snapshot['vat_number'],
            billingAddress: $snapshot['billing_address'],
            shippingAddress: $snapshot['shipping_address'],
            currency: $order->getCurrency(),
            subtotal: $totals['subtotal'],
            taxTotal: $totals['tax_total'],
            discountTotal: $totals['discount_total'],
            grandTotal: $totals['grand_total'],
            order: $order,
            idempotencyKey: $idempotencyKey,
            createdBy: EntityId::fromString($user->getId()),
        );

        $this->persistDocumentLines($document, $linePayloads);
        $this->assignAndPost($document, $user);

        return $document;
    }

    /** @return list<array<string, mixed>> */
    private function buildLinesFromOrder(Order $order): array
    {
        $lines = [];
        $index = 0;

        foreach ($order->getItems() as $item) {
            $lines[] = $this->lineFromOrderItem($item, $item->getQuantityOrdered(), $index++);
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    private function buildLinesFromDelivery(Delivery $delivery, string $currency): array
    {
        $lines = [];
        $index = 0;

        foreach ($delivery->getLines() as $deliveryLine) {
            if (!$deliveryLine instanceof DeliveryLine) {
                continue;
            }

            $lines[] = $this->lineFromOrderItem(
                $deliveryLine->getOrderItem(),
                $deliveryLine->getQuantity(),
                $index++,
            );
        }

        return $lines;
    }

    /**
     * Order items store their tax rate as a percentage (see CartService), the same
     * convention lineTotals() and document lines use, so it is copied through unchanged.
     *
     * @return array<string, mixed>
     */
    private function lineFromOrderItem(OrderItem $item, Quantity $quantity, int $sortOrder): array
    {
        $unitPrice = $item->getUnitPrice();
        $discountPerUnit = Money::of(
            bcdiv($item->getDiscountAmount()->amount(), $item->getQuantityOrdered()->amount(), 4),
            $unitPrice->currency(),
        );
        $discountAmount = Money::of(
            bcmul($discountPerUnit->amount(), $quantity->amount(), 4),
            $unitPrice->currency(),
        );
        $taxRate = bcadd($item->getTaxRate(), '0', 4);
        $totals = DocumentSnapshotBuilder::lineTotals($quantity, $unitPrice, $taxRate, $discountAmount);

        return [
            'source_line_id' => $item->getId(),
            'description' => $item->getProductName().' — '.$item->getVariantName(),
            'sku' => $item->getSku(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'discount_amount' => $discountAmount,
            'line_subtotal' => $totals['line_subtotal'],
            'line_tax' => $totals['line_tax'],
            'line_total' => $totals['line_total'],
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    private function buildManualLine(User $user, Customer $customer, string $currency, array $line, int $index): array
    {
        $position = $index + 1;
        $variantId = $this->optionalString($line, 'variant_id', $position);
        $description = $this->optionalString($line, 'description', $position);
        $sku = $this->optionalString($line, 'sku', $position);
        $unitPriceInput = $this->optionalString($line, 'unit_price', $position);

        if ($variantId !== null) {
            $variant = $this->findVariant($user, $variantId, $position);
            $description ??= $variant->getProduct()->getName().' — '.$variant->getName();
            $sku ??= $variant->getSku();

            if ($unitPriceInput === null) {
                $pricing = $this->pricingService->resolveForVariant(
                    $variant,
                    $user->companyId(),
                    EntityId::fromString($customer->getId()),
                );

                if (strtoupper($pricing['currency']) !== $currency) {
                    throw new BadRequestHttpException(sprintf(
                        'Line %d: catalog price is in %s; enter a unit price in %s.',
                        $position,
                        $pricing['currency'],
                        $currency,
                    ));
                }

                $unitPriceInput = $pricing['amount'];
            }
        }

        if ($description === null) {
            throw new BadRequestHttpException(sprintf('Line %d: description is required when no product is selected.', $position));
        }

        if ($unitPriceInput === null) {
            throw new BadRequestHttpException(sprintf('Line %d: unit_price is required when no product is selected.', $position));
        }

        $quantity = Quantity::of($this->decimal($line['quantity'] ?? null, $position, 'quantity'));

        if ($quantity->isZero()) {
            throw new BadRequestHttpException(sprintf('Line %d: quantity must be greater than zero.', $position));
        }

        $unitPrice = Money::of($this->decimal($unitPriceInput, $position, 'unit_price'), $currency);
        $taxRate = $this->decimal(
            $line['tax_rate'] ?? $this->taxSettingsService->defaultTaxRate($user->companyId()),
            $position,
            'tax_rate',
        );

        if (bccomp($taxRate, '100', 4) > 0) {
            throw new BadRequestHttpException(sprintf('Line %d: tax_rate is a percentage and cannot exceed 100.', $position));
        }

        $discount = Money::of($this->decimal($line['discount_amount'] ?? '0', $position, 'discount_amount'), $currency);

        if (bccomp($discount->amount(), bcmul($quantity->amount(), $unitPrice->amount(), 4), 4) > 0) {
            throw new BadRequestHttpException(sprintf('Line %d: discount cannot exceed the line amount.', $position));
        }

        $totals = DocumentSnapshotBuilder::lineTotals($quantity, $unitPrice, $taxRate, $discount);

        return [
            'source_line_id' => null,
            'description' => mb_substr($description, 0, 255),
            'sku' => mb_substr($sku ?? '', 0, 64),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'discount_amount' => $totals['discount_amount'],
            'line_subtotal' => $totals['line_subtotal'],
            'line_tax' => $totals['line_tax'],
            'line_total' => $totals['line_total'],
            'sort_order' => $index,
        ];
    }

    /** @param array<string, mixed> $line */
    private function optionalString(array $line, string $field, int $position): ?string
    {
        $value = $line[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new BadRequestHttpException(sprintf('Line %d: %s must be a string.', $position, $field));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** Validates a non-negative decimal with at most 4 fractional digits and returns it at scale 4. */
    private function decimal(mixed $value, int $position, string $field): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || !preg_match('/^\d+(\.\d{1,4})?$/', trim($value))) {
            throw new BadRequestHttpException(sprintf(
                'Line %d: %s must be a non-negative number with at most 4 decimal places.',
                $position,
                $field,
            ));
        }

        return bcadd(trim($value), '0', 4);
    }

    private function postDraft(CommercialDocument $document, User $user, ?string $dueDate): void
    {
        if ($document->getStatus() === DocumentStatus::Cancelled->value) {
            throw new BadRequestHttpException('Cancelled documents cannot be issued.');
        }

        $due = null;

        if ($document->getDocumentType() === DocumentType::Invoice) {
            try {
                $due = $dueDate !== null ? new \DateTimeImmutable($dueDate) : new \DateTimeImmutable('+30 days');
            } catch (\Exception) {
                throw new BadRequestHttpException('due_date must be a valid date (YYYY-MM-DD).');
            }
        }

        $this->assignAndPost($document, $user, dueDate: $due);
        $this->dispatchPdfGeneration($document);
    }

    /** @param list<array<string, mixed>> $linePayloads */
    private function persistDocumentLines(CommercialDocument $document, array $linePayloads): void
    {
        foreach ($linePayloads as $payload) {
            $line = new DocumentLine(
                EntityId::generate(),
                $document,
                $payload['source_line_id'],
                $payload['description'],
                $payload['sku'],
                $payload['quantity'],
                $payload['unit_price'],
                $payload['tax_rate'],
                $payload['discount_amount'],
                $payload['line_subtotal'],
                $payload['line_tax'],
                $payload['line_total'],
                $payload['sort_order'] ?? 0,
            );
            $this->entityManager->persist($line);
        }
    }

    private function assignAndPost(
        CommercialDocument $document,
        User $user,
        ?\DateTimeImmutable $dueDate = null,
    ): void {
        $number = $this->documentNumberService->nextNumber($user->companyId(), $document->getDocumentType());
        $document->assignNumber($number['number'], $number['fiscal_year']);
        $document->post(dueDate: $dueDate);
    }

    private function linkDocuments(
        CommercialDocument $source,
        CommercialDocument $target,
        DocumentRelationType $type,
    ): void {
        $relation = new DocumentRelation(
            EntityId::generate(),
            $source,
            $target,
            $type,
        );
        $this->entityManager->persist($relation);
    }

    private function linkToOrderDocumentIfExists(Order $order, CommercialDocument $deliveryNote): void
    {
        /** @var CommercialDocument|null $salesOrderDoc */
        $salesOrderDoc = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.order = :order')
            ->andWhere('d.documentType = :type')
            ->setParameter('order', $order)
            ->setParameter('type', DocumentType::SalesOrder)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($salesOrderDoc !== null) {
            $this->linkDocuments($salesOrderDoc, $deliveryNote, DocumentRelationType::DerivedFrom);
        }
    }

    private function dispatchPdfGeneration(CommercialDocument $document): void
    {
        $this->messageBus->dispatch(new GenerateDocumentPdf(
            $document->getId(),
            $document->companyId()->toString(),
        ));
    }

    private function findByIdempotency(User $user, ?string $idempotencyKey): ?CommercialDocument
    {
        if ($idempotencyKey === null) {
            return null;
        }

        /** @var CommercialDocument|null $existing */
        $existing = $this->entityManager->getRepository(CommercialDocument::class)->findOneBy([
            'companyId' => $user->companyId()->toString(),
            'idempotencyKey' => $idempotencyKey,
        ]);

        return $existing;
    }

    private function findOrder(User $user, string $orderId): Order
    {
        /** @var Order|null $order */
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'id' => $orderId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($order === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        return $order;
    }

    private function findCustomer(User $user, string $customerId): Customer
    {
        /** @var Customer|null $customer */
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy([
            'id' => $customerId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($customer === null) {
            throw new BadRequestHttpException('Customer not found.');
        }

        return $customer;
    }

    private function findVariant(User $user, string $variantId, int $position): ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($variant === null) {
            throw new BadRequestHttpException(sprintf('Line %d: product variant not found.', $position));
        }

        return $variant;
    }

    /** Guards against billing the same shipment twice; a cancelled or credited invoice frees the delivery again. */
    private function assertDeliveryNotInvoiced(Delivery $delivery): void
    {
        /** @var CommercialDocument|null $existing */
        $existing = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.delivery = :delivery')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.status NOT IN (:closed)')
            ->setParameter('delivery', $delivery)
            ->setParameter('type', DocumentType::Invoice)
            ->setParameter('closed', [InvoiceStatus::Cancelled->value, InvoiceStatus::Credited->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing !== null) {
            throw new ConflictHttpException(sprintf(
                'Delivery %s is already invoiced (%s).',
                $delivery->getReference(),
                $existing->getDocumentNumber() ?? 'draft invoice',
            ));
        }
    }

    private function findDelivery(User $user, string $deliveryId): Delivery
    {
        /** @var Delivery|null $delivery */
        $delivery = $this->entityManager->getRepository(Delivery::class)->findOneBy([
            'id' => $deliveryId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($delivery === null) {
            throw new NotFoundHttpException('Delivery not found.');
        }

        return $delivery;
    }

    private function findInvoice(User $user, string $invoiceId): CommercialDocument
    {
        $document = $this->findDocument($user, $invoiceId);

        if ($document->getDocumentType() !== DocumentType::Invoice) {
            throw new NotFoundHttpException('Invoice not found.');
        }

        return $document;
    }

    private function findDocument(User $user, string $documentId): CommercialDocument
    {
        /** @var CommercialDocument|null $document */
        $document = $this->entityManager->getRepository(CommercialDocument::class)->findOneBy([
            'id' => $documentId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        if ($user->isPortalUser()) {
            $portalCustomer = $this->resolvePortalCustomer($user);

            if ($document->getCustomer()->getId() !== $portalCustomer->getId()) {
                throw new NotFoundHttpException('Document not found.');
            }
        }

        return $document;
    }

    private function resolvePortalCustomer(User $user): Customer
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new BadRequestHttpException('Portal customer profile not found.');
        }

        return $portalUser->getCustomer();
    }
}
