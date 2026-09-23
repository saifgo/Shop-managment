<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Message\GenerateDocumentPdf;
use App\Application\Shared\PaginatedResult;
use App\Domain\Documents\DocumentRelationType;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Documents\DocumentFile;
use App\Infrastructure\Persistence\Entity\Documents\DocumentLine;
use App\Infrastructure\Persistence\Entity\Documents\DocumentRelation;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Sales\Delivery;
use App\Infrastructure\Persistence\Entity\Sales\DeliveryLine;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use App\Infrastructure\Persistence\UnitOfWork;
use App\Infrastructure\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

final class DocumentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private DocumentNumberService $documentNumberService,
        private DocumentSnapshotBuilder $snapshotBuilder,
        private DocumentStorage $documentStorage,
        private MessageBusInterface $messageBus,
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
                grandTotal: $totals['grand_total'],
                order: $orderRef,
                delivery: $deliveryRef,
                notes: $payload['notes'] ?? null,
                idempotencyKey: $idempotencyKey,
                createdBy: EntityId::fromString($user->getId()),
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

            $this->assignAndPost(
                $document,
                $user,
                dueDate: $dueDate !== null ? new \DateTimeImmutable($dueDate) : new \DateTimeImmutable('+30 days'),
            );
            $this->dispatchPdfGeneration($document);

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
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.documentType = :type')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('type', DocumentType::Invoice)
            ->orderBy('d.createdAt', 'DESC');

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

        if ($file === null) {
            throw new NotFoundHttpException('PDF not yet generated.');
        }

        $content = $this->documentStorage->read($file->getStorageKey());

        if ($content === null) {
            throw new NotFoundHttpException('PDF file missing from storage.');
        }

        return [
            'content' => $content,
            'mime_type' => $file->getMimeType(),
            'filename' => ($document->getDocumentNumber() ?? $document->getId()).'.pdf',
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

    /** @return array<string, mixed> */
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
        $totals = DocumentSnapshotBuilder::lineTotals($quantity, $unitPrice, $item->getTaxRate(), $discountAmount);

        return [
            'source_line_id' => $item->getId(),
            'description' => $item->getProductName().' — '.$item->getVariantName(),
            'sku' => $item->getSku(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $item->getTaxRate(),
            'discount_amount' => $discountAmount,
            'line_subtotal' => $totals['line_subtotal'],
            'line_tax' => $totals['line_tax'],
            'line_total' => $totals['line_total'],
            'sort_order' => $sortOrder,
        ];
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

    private function resolvePortalCustomer(User $user): \App\Infrastructure\Persistence\Entity\Customer\Customer
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new BadRequestHttpException('Portal customer profile not found.');
        }

        return $portalUser->getCustomer();
    }
}
