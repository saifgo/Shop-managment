<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use App\Application\Inventory\AvailabilityService;
use App\Application\Inventory\StockLedgerService;
use App\Application\Shared\PaginatedResult;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Domain\Purchasing\SupplierPaymentStatus;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Purchasing\PurchaseOrder;
use App\Infrastructure\Persistence\Entity\Purchasing\PurchaseOrderItem;
use App\Infrastructure\Persistence\Entity\Purchasing\PurchaseReceipt;
use App\Infrastructure\Persistence\Entity\Purchasing\PurchaseReceiptItem;
use App\Infrastructure\Persistence\Entity\Purchasing\Supplier;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierInvoice;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierPayment;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierPaymentAllocation;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierProduct;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PurchasingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private StockLedgerService $stockLedgerService,
        private AvailabilityService $availabilityService,
    ) {}

    /**
     * @param array{code: string, name: string, contact_email?: string, contact_phone?: string, address?: string, tax_id?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function createSupplier(User $user, array $payload): array
    {
        $supplier = new Supplier(
            EntityId::generate(),
            $user->companyId(),
            $payload['code'],
            $payload['name'],
            $payload['contact_email'] ?? null,
            $payload['contact_phone'] ?? null,
            $payload['address'] ?? null,
            $payload['tax_id'] ?? null,
        );
        $this->entityManager->persist($supplier);

        return $this->serializeSupplier($supplier);
    }

    /** @return PaginatedResult<array<string, mixed>> */
    public function listSuppliers(User $user, int $page, int $perPage): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Supplier::class, 's')
            ->where('s.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('s.name', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        /** @var list<Supplier> $suppliers */
        $suppliers = $qb->getQuery()->getResult();
        $items = array_map(fn(Supplier $s) => $this->serializeSupplier($s), $suppliers);

        return new PaginatedResult($items, $page, $perPage, count($suppliers));
    }

    /** @return array<string, mixed> */
    public function getSupplier(User $user, string $supplierId): array
    {
        return $this->serializeSupplier($this->findSupplier($user, $supplierId));
    }

    /**
     * @param array{variant_id: string, purchase_price: string, currency: string, supplier_sku?: string, lead_time_days?: int, minimum_order_qty?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function addSupplierProduct(User $user, string $supplierId, array $payload): array
    {
        $supplier = $this->findSupplier($user, $supplierId);
        $variant = $this->findVariant($user, $payload['variant_id']);

        $product = new SupplierProduct(
            EntityId::generate(),
            $supplier,
            $variant,
            Money::of($payload['purchase_price'], $payload['currency']),
            $payload['supplier_sku'] ?? null,
            $payload['lead_time_days'] ?? null,
            $payload['minimum_order_qty'] ?? null,
        );
        $this->entityManager->persist($product);

        return $this->serializeSupplierProduct($product);
    }

    /**
     * @param array{supplier_id: string, currency: string, expected_at?: string, notes?: string, items: list<array{variant_id: string, quantity: string, unit_price: string}>} $payload
     *
     * @return array<string, mixed>
     */
    public function createPurchaseOrder(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var PurchaseOrder|null $existing */
                $existing = $this->entityManager->getRepository(PurchaseOrder::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializePurchaseOrder($existing);
                }
            }

            $supplier = $this->findSupplier($user, $payload['supplier_id']);

            if ($payload['items'] === []) {
                throw new BadRequestHttpException('At least one PO line is required.');
            }

            $po = new PurchaseOrder(
                EntityId::generate(),
                $user->companyId(),
                $supplier,
                $this->generatePoReference($user->companyId()),
                $payload['currency'],
                isset($payload['expected_at']) ? new \DateTimeImmutable($payload['expected_at']) : null,
                $payload['notes'] ?? null,
                EntityId::fromString($user->getId()),
                $idempotencyKey,
            );

            foreach ($payload['items'] as $line) {
                $variant = $this->findVariant($user, $line['variant_id']);
                $item = new PurchaseOrderItem(
                    EntityId::generate(),
                    $po,
                    $variant,
                    Quantity::of($line['quantity']),
                    Money::of($line['unit_price'], $payload['currency']),
                );
                $this->entityManager->persist($item);
            }

            $po->setStatus(PurchaseOrderStatus::Sent);
            $this->entityManager->persist($po);

            return $this->serializePurchaseOrder($po);
        });
    }

    /** @return PaginatedResult<array<string, mixed>> */
    public function listPurchaseOrders(User $user, int $page, int $perPage, ?string $supplierId = null): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(PurchaseOrder::class, 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        if ($supplierId !== null) {
            $qb->andWhere('p.supplier = :supplier')->setParameter('supplier', $supplierId);
        }

        /** @var list<PurchaseOrder> $orders */
        $orders = $qb->getQuery()->getResult();
        $items = array_map(fn(PurchaseOrder $po) => $this->serializePurchaseOrderSummary($po), $orders);

        return new PaginatedResult($items, $page, $perPage, count($orders));
    }

    /** @return array<string, mixed> */
    public function getPurchaseOrder(User $user, string $poId): array
    {
        return $this->serializePurchaseOrder($this->findPurchaseOrder($user, $poId));
    }

    /**
     * @param list<array{purchase_order_item_id: string, quantity: string}> $lines
     *
     * @return array<string, mixed>
     */
    public function receivePurchaseOrder(User $user, string $poId, array $lines, ?string $notes = null, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $poId, $lines, $notes, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var PurchaseReceipt|null $existing */
                $existing = $this->entityManager->getRepository(PurchaseReceipt::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializeReceipt($existing);
                }
            }

            $po = $this->findPurchaseOrder($user, $poId);
            $location = $this->availabilityService->resolveDefaultLocation($user->companyId());

            if ($location === null) {
                throw new BadRequestHttpException('No stock location configured.');
            }

            if ($lines === []) {
                throw new BadRequestHttpException('At least one receipt line is required.');
            }

            $itemsById = [];

            foreach ($po->getItems() as $item) {
                $itemsById[$item->getId()] = $item;
            }

            $receipt = new PurchaseReceipt(
                EntityId::generate(),
                $user->companyId(),
                $po,
                $this->generateReceiptReference($user->companyId()),
                $notes,
                EntityId::fromString($user->getId()),
                $idempotencyKey,
            );

            foreach ($lines as $linePayload) {
                $poItem = $itemsById[$linePayload['purchase_order_item_id']] ?? null;

                if (!$poItem instanceof PurchaseOrderItem) {
                    throw new BadRequestHttpException('Invalid PO item.');
                }

                $quantity = Quantity::of($linePayload['quantity']);

                if ($quantity->compare($poItem->getRemainingReceivableQuantity()) > 0) {
                    throw new BadRequestHttpException('Receipt quantity exceeds remaining PO quantity.');
                }

                $poItem->recordReceipt($quantity);
                $receiptItem = new PurchaseReceiptItem(
                    EntityId::generate(),
                    $receipt,
                    $poItem,
                    $quantity,
                );
                $this->entityManager->persist($receiptItem);

                $this->stockLedgerService->postMovement(
                    $user->companyId(),
                    $poItem->getVariant(),
                    $location,
                    StockMovementType::PurchaseReceipt,
                    $quantity->amount(),
                    '0.0000',
                    'purchase_receipt',
                    EntityId::fromString($receipt->getId()),
                    $receipt->getReference(),
                    'PO ' . $po->getReference(),
                    EntityId::fromString($user->getId()),
                );
            }

            $po->recalculateReceiptStatus();
            $this->entityManager->persist($receipt);

            return $this->serializeReceipt($receipt);
        });
    }

    /**
     * @param array{supplier_id: string, invoice_number: string, total_amount: string, currency: string, issued_at: string, purchase_order_id?: string, due_date?: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function createSupplierInvoice(User $user, array $payload): array
    {
        $supplier = $this->findSupplier($user, $payload['supplier_id']);
        $po = null;

        if (isset($payload['purchase_order_id'])) {
            $po = $this->findPurchaseOrder($user, $payload['purchase_order_id']);
        }

        $invoice = new SupplierInvoice(
            EntityId::generate(),
            $user->companyId(),
            $supplier,
            $payload['invoice_number'],
            Money::of($payload['total_amount'], $payload['currency']),
            new \DateTimeImmutable($payload['issued_at']),
            $po,
            isset($payload['due_date']) ? new \DateTimeImmutable($payload['due_date']) : null,
            $payload['notes'] ?? null,
        );
        $this->entityManager->persist($invoice);

        return $this->serializeSupplierInvoice($invoice);
    }

    /**
     * @param array{supplier_id: string, amount: string, currency: string, method: string, payment_date: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function recordSupplierPayment(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var SupplierPayment|null $existing */
                $existing = $this->entityManager->getRepository(SupplierPayment::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializeSupplierPayment($existing);
                }
            }

            $supplier = $this->findSupplier($user, $payload['supplier_id']);
            $payment = new SupplierPayment(
                EntityId::generate(),
                $user->companyId(),
                $this->generateSupplierPaymentReference($user->companyId()),
                $supplier,
                Money::of($payload['amount'], $payload['currency']),
                PaymentMethod::from($payload['method']),
                new \DateTimeImmutable($payload['payment_date']),
                $payload['notes'] ?? null,
                $idempotencyKey,
                EntityId::fromString($user->getId()),
            );
            $this->entityManager->persist($payment);

            return $this->serializeSupplierPayment($payment);
        });
    }

    /**
     * @param list<array{invoice_id: string, amount: string}> $allocations
     *
     * @return array<string, mixed>
     */
    public function allocateSupplierPayment(User $user, string $paymentId, array $allocations): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $paymentId, $allocations): array {
            $payment = $this->findSupplierPayment($user, $paymentId);

            foreach ($allocations as $allocationPayload) {
                $invoice = $this->findSupplierInvoice($user, $allocationPayload['invoice_id']);
                $amount = Money::of($allocationPayload['amount'], $payment->getAmount()->currency());

                if ($payment->getSupplier()->getId() !== $invoice->getSupplier()->getId()) {
                    throw new BadRequestHttpException('Payment and invoice must belong to the same supplier.');
                }

                if ($amount->compare($invoice->getAmountDue()) > 0) {
                    throw new BadRequestHttpException('Allocation exceeds invoice amount due.');
                }

                if ($amount->compare($payment->getUnallocatedAmount()) > 0) {
                    throw new BadRequestHttpException('Allocation exceeds unallocated payment amount.');
                }

                $allocation = new SupplierPaymentAllocation(
                    EntityId::generate(),
                    $payment,
                    $invoice,
                    $amount,
                );
                $payment->applyAllocation($amount);
                $invoice->applyPayment($amount);
                $this->entityManager->persist($allocation);
            }

            return $this->serializeSupplierPayment($payment);
        });
    }

    /** @return array{amount: string, currency: string, breakdown: array<string, string>} */
    public function supplierBalance(User $user, string $supplierId): array
    {
        $supplier = $this->findSupplier($user, $supplierId);
        $currency = 'TND';

        $issued = (string) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(i.totalAmount), 0)')
            ->from(SupplierInvoice::class, 'i')
            ->where('i.companyId = :companyId')
            ->andWhere('i.supplier = :supplier')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('supplier', $supplier)
            ->getQuery()
            ->getSingleScalarResult();

        $allocated = (string) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(a.allocatedAmount), 0)')
            ->from(SupplierPaymentAllocation::class, 'a')
            ->join('a.payment', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('p.supplier = :supplier')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('supplier', $supplier)
            ->getQuery()
            ->getSingleScalarResult();

        $outstanding = bcsub($issued, $allocated, 4);

        return [
            'amount' => Money::of($outstanding, $currency)->amount(),
            'currency' => $currency,
            'breakdown' => [
                'total_issued_invoices' => Money::of($issued, $currency)->amount(),
                'total_allocated_payments' => Money::of($allocated, $currency)->amount(),
            ],
        ];
    }

    private function findSupplier(User $user, string $supplierId): Supplier
    {
        /** @var Supplier|null $supplier */
        $supplier = $this->entityManager->getRepository(Supplier::class)->findOneBy([
            'id' => $supplierId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($supplier === null) {
            throw new NotFoundHttpException('Supplier not found.');
        }

        return $supplier;
    }

    private function findVariant(User $user, string $variantId): ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($variant === null) {
            throw new BadRequestHttpException('Product variant not found.');
        }

        return $variant;
    }

    private function findPurchaseOrder(User $user, string $poId): PurchaseOrder
    {
        /** @var PurchaseOrder|null $po */
        $po = $this->entityManager->getRepository(PurchaseOrder::class)->findOneBy([
            'id' => $poId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($po === null) {
            throw new NotFoundHttpException('Purchase order not found.');
        }

        return $po;
    }

    private function findSupplierPayment(User $user, string $paymentId): SupplierPayment
    {
        /** @var SupplierPayment|null $payment */
        $payment = $this->entityManager->getRepository(SupplierPayment::class)->findOneBy([
            'id' => $paymentId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($payment === null) {
            throw new NotFoundHttpException('Supplier payment not found.');
        }

        return $payment;
    }

    private function findSupplierInvoice(User $user, string $invoiceId): SupplierInvoice
    {
        /** @var SupplierInvoice|null $invoice */
        $invoice = $this->entityManager->getRepository(SupplierInvoice::class)->findOneBy([
            'id' => $invoiceId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($invoice === null) {
            throw new BadRequestHttpException('Supplier invoice not found.');
        }

        return $invoice;
    }

    private function generatePoReference(EntityId $companyId): string
    {
        return $this->generateReference($companyId, 'PO-', PurchaseOrder::class);
    }

    private function generateReceiptReference(EntityId $companyId): string
    {
        return $this->generateReference($companyId, 'RCV-', PurchaseReceipt::class);
    }

    private function generateSupplierPaymentReference(EntityId $companyId): string
    {
        return $this->generateReference($companyId, 'SPAY-', SupplierPayment::class);
    }

    /** @param class-string $entityClass */
    private function generateReference(EntityId $companyId, string $prefix, string $entityClass): string
    {
        $datedPrefix = $prefix . date('Ymd') . '-';
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->where('e.companyId = :companyId')
            ->andWhere('e.reference LIKE :prefix')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('prefix', $datedPrefix . '%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%04d', $datedPrefix, $count + 1);
    }

    /** @return array<string, mixed> */
    private function serializeSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->getId(),
            'code' => $supplier->getCode(),
            'name' => $supplier->getName(),
            'contact_email' => $supplier->getContactEmail(),
            'contact_phone' => $supplier->getContactPhone(),
            'address' => $supplier->getAddress(),
            'tax_id' => $supplier->getTaxId(),
            'is_active' => $supplier->isActive(),
            'created_at' => $supplier->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSupplierProduct(SupplierProduct $product): array
    {
        return [
            'id' => $product->getId(),
            'supplier_id' => $product->getSupplier()->getId(),
            'variant_id' => $product->getVariant()->getId(),
            'supplier_sku' => $product->getSupplierSku(),
            'purchase_price' => [
                'amount' => $product->getPurchasePrice()->amount(),
                'currency' => $product->getPurchasePrice()->currency(),
            ],
            'lead_time_days' => $product->getLeadTimeDays(),
            'minimum_order_qty' => $product->getMinimumOrderQty(),
            'is_active' => $product->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePurchaseOrderSummary(PurchaseOrder $po): array
    {
        return [
            'id' => $po->getId(),
            'reference' => $po->getReference(),
            'status' => $po->getStatus()->value,
            'supplier_id' => $po->getSupplier()->getId(),
            'supplier_name' => $po->getSupplier()->getName(),
            'currency' => $po->getCurrency(),
            'grand_total' => ['amount' => $po->getGrandTotal()->amount(), 'currency' => $po->getCurrency()],
            'created_at' => $po->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePurchaseOrder(PurchaseOrder $po): array
    {
        $items = [];

        foreach ($po->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'variant_id' => $item->getVariant()->getId(),
                'sku' => $item->getVariant()->getSku(),
                'quantity_ordered' => $item->getQuantityOrdered()->amount(),
                'quantity_received' => $item->getQuantityReceived()->amount(),
                'unit_price' => ['amount' => $item->getUnitPrice()->amount(), 'currency' => $po->getCurrency()],
                'line_total' => ['amount' => $item->getLineTotal()->amount(), 'currency' => $po->getCurrency()],
            ];
        }

        return [
            ...$this->serializePurchaseOrderSummary($po),
            'expected_at' => $po->getExpectedAt()?->format('Y-m-d'),
            'notes' => $po->getNotes(),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeReceipt(PurchaseReceipt $receipt): array
    {
        $items = [];

        foreach ($receipt->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'purchase_order_item_id' => $item->getPurchaseOrderItem()->getId(),
                'quantity' => $item->getQuantity()->amount(),
            ];
        }

        return [
            'id' => $receipt->getId(),
            'reference' => $receipt->getReference(),
            'purchase_order_id' => $receipt->getPurchaseOrder()->getId(),
            'received_at' => $receipt->getReceivedAt()->format(DATE_ATOM),
            'notes' => $receipt->getNotes(),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSupplierInvoice(SupplierInvoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'supplier_id' => $invoice->getSupplier()->getId(),
            'purchase_order_id' => $invoice->getPurchaseOrder()?->getId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'status' => $invoice->getStatus()->value,
            'total_amount' => ['amount' => $invoice->getTotalAmount()->amount(), 'currency' => $invoice->getCurrency()],
            'amount_paid' => ['amount' => $invoice->getAmountPaid()->amount(), 'currency' => $invoice->getCurrency()],
            'amount_due' => ['amount' => $invoice->getAmountDue()->amount(), 'currency' => $invoice->getCurrency()],
            'issued_at' => $invoice->getIssuedAt()->format('Y-m-d'),
            'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
            'notes' => $invoice->getNotes(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSupplierPayment(SupplierPayment $payment): array
    {
        $allocations = [];

        foreach ($payment->getAllocations() as $allocation) {
            $allocations[] = [
                'id' => $allocation->getId(),
                'invoice_id' => $allocation->getInvoice()->getId(),
                'invoice_number' => $allocation->getInvoice()->getInvoiceNumber(),
                'allocated_amount' => [
                    'amount' => $allocation->getAllocatedAmount()->amount(),
                    'currency' => $payment->getAmount()->currency(),
                ],
            ];
        }

        return [
            'id' => $payment->getId(),
            'reference' => $payment->getReference(),
            'supplier_id' => $payment->getSupplier()->getId(),
            'amount' => ['amount' => $payment->getAmount()->amount(), 'currency' => $payment->getAmount()->currency()],
            'allocated_amount' => ['amount' => $payment->getAllocatedAmount()->amount(), 'currency' => $payment->getAmount()->currency()],
            'unallocated_amount' => ['amount' => $payment->getUnallocatedAmount()->amount(), 'currency' => $payment->getAmount()->currency()],
            'method' => $payment->getMethod()->value,
            'status' => $payment->getStatus()->value,
            'payment_date' => $payment->getPaymentDate()->format('Y-m-d'),
            'notes' => $payment->getNotes(),
            'allocations' => $allocations,
        ];
    }
}
