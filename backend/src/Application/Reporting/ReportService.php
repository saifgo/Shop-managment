<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Finance\FinanceProjectionService;
use App\Application\Catalog\ProductService;
use App\Application\Production\ProductionYieldService;
use App\Domain\Inventory\StockMovementType;
use App\Infrastructure\Persistence\Entity\Inventory\StockMovement;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Documents\DocumentLine;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierInvoice;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierPaymentAllocation;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use Doctrine\ORM\EntityManagerInterface;

final class ReportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FinanceProjectionService $financeProjectionService,
        private ProductionYieldService $productionYieldService,
    ) {}

    /** @return array<string, mixed> */
    public function sales(User $user, ?string $from = null, ?string $to = null): array
    {
        $companyId = $user->companyId()->toString();
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(o.grandTotalAmount), 0) AS revenue, COUNT(o.id) AS order_count')
            ->from(Order::class, 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.status != :cancelled')
            ->setParameter('companyId', $companyId)
            ->setParameter('cancelled', 'CANCELLED');

        $this->applyDateRange($qb, 'o.createdAt', $from, $to);

        $result = $qb->getQuery()->getSingleResult();
        $currency = 'TND';

        return [
            'period' => ['from' => $from, 'to' => $to],
            'order_count' => (int) ($result['order_count'] ?? 0),
            'revenue' => ['amount' => Money::of((string) ($result['revenue'] ?? '0'), $currency)->amount(), 'currency' => $currency],
        ];
    }

    /**
     * Gross margin: what was invoiced (net of VAT and credit notes) against what the pieces that
     * left the workshop cost, valued at the moving-average cost they were issued at. Pieces with
     * no known cost (no recipe, no purchase price) count as zero, which overstates the margin;
     * `uncosted_units` says how many so the figure is not trusted blindly.
     *
     * @return array<string, mixed>
     */
    public function margin(User $user, ?string $from = null, ?string $to = null): array
    {
        $companyId = $user->companyId()->toString();
        $currency = 'TND';

        $revenue = '0.0000';
        foreach ([[DocumentType::Invoice, 1], [DocumentType::CreditNote, -1]] as [$type, $sign]) {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('COALESCE(SUM(l.lineSubtotalAmount), 0)')
                ->from(DocumentLine::class, 'l')
                ->join('l.document', 'd')
                ->where('d.companyId = :companyId')
                ->andWhere('d.documentType = :type')
                ->andWhere('d.isPosted = true')
                ->andWhere('d.status != :cancelled')
                ->setParameter('companyId', $companyId)
                ->setParameter('type', $type)
                ->setParameter('cancelled', InvoiceStatus::Cancelled->value);
            $this->applyDateRange($qb, 'd.issuedAt', $from, $to);
            $amount = bcadd((string) $qb->getQuery()->getSingleScalarResult(), '0', 4);
            $revenue = $sign > 0 ? bcadd($revenue, $amount, 4) : bcsub($revenue, $amount, 4);
        }

        $cost = '0.0000';
        $uncosted = '0.0000';
        foreach ([[StockMovementType::SaleShipment, 1], [StockMovementType::ReturnReceipt, -1]] as [$movementType, $sign]) {
            $qb = $this->entityManager->createQueryBuilder()
                ->select(
                    'COALESCE(SUM(ABS(m.quantityDelta) * COALESCE(m.unitCost, 0)), 0) AS cost',
                    'COALESCE(SUM(CASE WHEN COALESCE(m.unitCost, 0) = 0 THEN ABS(m.quantityDelta) ELSE 0 END), 0) AS uncosted',
                )
                ->from(StockMovement::class, 'm')
                ->where('m.companyId = :companyId')
                ->andWhere('m.movementType = :type')
                ->setParameter('companyId', $companyId)
                ->setParameter('type', $movementType);
            $this->applyDateRange($qb, 'm.createdAt', $from, $to);
            $row = $qb->getQuery()->getSingleResult();
            $amount = bcadd((string) $row['cost'], '0', 4);
            $units = bcadd((string) $row['uncosted'], '0', 4);
            $cost = $sign > 0 ? bcadd($cost, $amount, 4) : bcsub($cost, $amount, 4);
            $uncosted = $sign > 0 ? bcadd($uncosted, $units, 4) : bcsub($uncosted, $units, 4);
        }

        $margin = bcsub($revenue, $cost, 4);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'revenue' => ['amount' => Money::of($revenue, $currency)->amount(), 'currency' => $currency],
            'cost' => ['amount' => Money::of($cost, $currency)->amount(), 'currency' => $currency],
            'margin' => ['amount' => bcadd($margin, '0', 4), 'currency' => $currency],
            'margin_pct' => bccomp($revenue, '0', 4) > 0 ? bcmul(bcdiv($margin, $revenue, 6), '100', 2) : null,
            'uncosted_units' => bccomp($uncosted, '0', 4) > 0 ? $uncosted : '0.0000',
            'note' => 'Revenue excludes VAT and credit notes. Cost is the average cost of the goods shipped, net of sellable returns.',
        ];
    }

    /** @return array<string, mixed> */
    public function stock(User $user): array
    {
        /** @var list<StockBalance> $balances */
        $balances = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(StockBalance::class, 'b')
            ->where('b.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('b.physicalOnHand', 'ASC')
            ->getQuery()
            ->getResult();

        $items = [];
        $value = ['finished_good' => '0.0000', 'raw_material' => '0.0000'];

        foreach ($balances as $balance) {
            $variant = $balance->getVariant();
            $product = $variant->getProduct();
            $available = $balance->getAvailableToSell()->amount();
            $stockValue = $balance->getValue();
            $value[$product->getKind()->value] = bcadd($value[$product->getKind()->value], $stockValue, 4);
            $items[] = [
                'variant_id' => $variant->getId(),
                'sku' => $variant->getSku(),
                'product_name' => $product->getName(),
                'kind' => $product->getKind()->value,
                'unit' => $product->getUnit(),
                'physical_on_hand' => $balance->getPhysicalOnHand()->amount(),
                'reserved' => $balance->getReserved()->amount(),
                'available_to_sell' => $available,
                'reorder_level' => $variant->getReorderLevel(),
                'average_cost' => $balance->getAverageCost(),
                'stock_value' => $stockValue,
                'is_low_stock' => $variant->isLowStock($available, ProductService::LOW_STOCK_THRESHOLD),
            ];
        }

        return [
            'items' => $items,
            'low_stock_count' => count(array_filter($items, static fn(array $item): bool => $item['is_low_stock'])),
            'total_stock_value' => bcadd($value['finished_good'], $value['raw_material'], 4),
            'finished_goods_value' => $value['finished_good'],
            'raw_materials_value' => $value['raw_material'],
            'currency' => 'TND',
        ];
    }

    /** @return array<string, mixed> */
    public function productionYield(User $user, ?string $from = null, ?string $to = null): array
    {
        return $this->productionYieldService->summary($user->companyId()->toString(), $from, $to);
    }

    /** @return array<string, mixed> */
    public function receivablesAging(User $user): array
    {
        return $this->financeProjectionService->agingSummary($user);
    }

    /** @return array<string, mixed> */
    public function payablesAging(User $user): array
    {
        $companyId = $user->companyId()->toString();
        $currency = 'TND';
        $today = new \DateTimeImmutable('today');
        $buckets = [
            'current' => '0.0000',
            '1_30_days' => '0.0000',
            '31_60_days' => '0.0000',
            '61_90_days' => '0.0000',
            'over_90_days' => '0.0000',
        ];

        /** @var list<SupplierInvoice> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SupplierInvoice::class, 'i')
            ->where('i.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();

        foreach ($invoices as $invoice) {
            $allocated = (string) $this->entityManager->createQueryBuilder()
                ->select('COALESCE(SUM(a.allocatedAmount), 0)')
                ->from(SupplierPaymentAllocation::class, 'a')
                ->where('a.invoice = :invoice')
                ->setParameter('invoice', $invoice)
                ->getQuery()
                ->getSingleScalarResult();

            $due = bcsub($invoice->getTotalAmount()->amount(), $allocated, 4);

            if (bccomp($due, '0.0000', 4) <= 0) {
                continue;
            }

            $dueDate = $invoice->getDueDate() ?? $invoice->getIssuedAt()?->setTime(0, 0);
            $days = $dueDate !== null ? (int) $today->diff($dueDate)->format('%r%a') : 0;

            if ($days >= 0) {
                $buckets['current'] = bcadd($buckets['current'], $due, 4);
            } elseif ($days >= -30) {
                $buckets['1_30_days'] = bcadd($buckets['1_30_days'], $due, 4);
            } elseif ($days >= -60) {
                $buckets['31_60_days'] = bcadd($buckets['31_60_days'], $due, 4);
            } elseif ($days >= -90) {
                $buckets['61_90_days'] = bcadd($buckets['61_90_days'], $due, 4);
            } else {
                $buckets['over_90_days'] = bcadd($buckets['over_90_days'], $due, 4);
            }
        }

        foreach ($buckets as $key => $amount) {
            $buckets[$key] = ['amount' => Money::of($amount, $currency)->amount(), 'currency' => $currency];
        }

        return ['payables_aging' => $buckets];
    }

    /** @return array<string, mixed> */
    public function issuedInvoices(User $user, ?string $from = null, ?string $to = null): array
    {
        $companyId = $user->companyId()->toString();
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(d.grandTotalAmount), 0) AS total, COUNT(d.id) AS invoice_count')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->andWhere('d.status NOT IN (:closed)')
            ->setParameter('companyId', $companyId)
            ->setParameter('type', DocumentType::Invoice)
            ->setParameter('closed', [InvoiceStatus::Cancelled->value]);

        $this->applyDateRange($qb, 'd.issuedAt', $from, $to);
        $result = $qb->getQuery()->getSingleResult();
        $currency = 'TND';

        return [
            'period' => ['from' => $from, 'to' => $to],
            'invoice_count' => (int) ($result['invoice_count'] ?? 0),
            'total_invoiced' => ['amount' => Money::of((string) ($result['total'] ?? '0'), $currency)->amount(), 'currency' => $currency],
        ];
    }

    private function applyDateRange(\Doctrine\ORM\QueryBuilder $qb, string $field, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $qb->andWhere($field . ' >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }

        if ($to !== null && $to !== '') {
            $qb->andWhere($field . ' <= :to')->setParameter('to', (new \DateTimeImmutable($to))->setTime(23, 59, 59));
        }
    }
}
