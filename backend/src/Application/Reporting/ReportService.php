<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Finance\FinanceProjectionService;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Documents\DocumentLine;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Production\StageExecution;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierInvoice;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierPaymentAllocation;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use Doctrine\ORM\EntityManagerInterface;

final class ReportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FinanceProjectionService $financeProjectionService,
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

    /** @return array<string, mixed> */
    public function margin(User $user, ?string $from = null, ?string $to = null): array
    {
        $companyId = $user->companyId()->toString();
        $currency = 'TND';

        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(l.lineTotalAmount), 0) AS revenue')
            ->from(DocumentLine::class, 'l')
            ->join('l.document', 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->setParameter('companyId', $companyId)
            ->setParameter('type', DocumentType::Invoice);

        $this->applyDateRange($qb, 'd.issuedAt', $from, $to);
        $revenue = (string) $qb->getQuery()->getSingleScalarResult();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'revenue' => ['amount' => Money::of($revenue, $currency)->amount(), 'currency' => $currency],
            'cost' => null,
            'margin' => null,
            'margin_pct' => null,
            'note' => 'Cost data unavailable; margin requires purchase cost linkage.',
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

        foreach ($balances as $balance) {
            $variant = $balance->getVariant();
            $items[] = [
                'variant_id' => $variant->getId(),
                'sku' => $variant->getSku(),
                'product_name' => $variant->getProduct()->getName(),
                'physical_on_hand' => $balance->getPhysicalOnHand()->amount(),
                'reserved' => $balance->getReserved()->amount(),
                'available_to_sell' => $balance->getAvailableToSell()->amount(),
                'is_low_stock' => bccomp($balance->getAvailableToSell()->amount(), '5.0000', 4) < 0,
            ];
        }

        return [
            'items' => $items,
            'low_stock_count' => count(array_filter($items, static fn(array $item): bool => $item['is_low_stock'])),
        ];
    }

    /** @return array<string, mixed> */
    public function productionYield(User $user, ?string $from = null, ?string $to = null): array
    {
        $companyId = $user->companyId()->toString();
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'COALESCE(SUM(se.inputQuantity), 0) AS input_total',
                'COALESCE(SUM(se.acceptedOutputQuantity), 0) AS output_total',
                'COALESCE(SUM(se.lossQuantity), 0) AS loss_total',
                'COUNT(se.id) AS stage_count',
            )
            ->from(StageExecution::class, 'se')
            ->join('se.productionOrder', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('se.completedAt IS NOT NULL')
            ->setParameter('companyId', $companyId);

        $this->applyDateRange($qb, 'se.completedAt', $from, $to);
        $result = $qb->getQuery()->getSingleResult();

        $input = (string) ($result['input_total'] ?? '0.0000');
        $output = (string) ($result['output_total'] ?? '0.0000');
        $loss = (string) ($result['loss_total'] ?? '0.0000');
        $yieldPct = bccomp($input, '0.0000', 4) > 0
            ? bcmul(bcdiv($output, $input, 6), '100', 2)
            : null;

        return [
            'period' => ['from' => $from, 'to' => $to],
            'stage_count' => (int) ($result['stage_count'] ?? 0),
            'input_total' => $input,
            'output_total' => $output,
            'loss_total' => $loss,
            'yield_pct' => $yieldPct,
        ];
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
