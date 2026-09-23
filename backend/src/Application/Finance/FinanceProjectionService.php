<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Payments\CustomerReceivablesService;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Finance\Expense;
use App\Infrastructure\Persistence\Entity\Finance\Income;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierInvoice;
use App\Infrastructure\Persistence\Entity\Purchasing\SupplierPaymentAllocation;
use Doctrine\ORM\EntityManagerInterface;

final class FinanceProjectionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CustomerReceivablesService $receivablesService,
    ) {}

    /** @return array<string, mixed> */
    public function cashPosition(User $user): array
    {
        $currency = 'TND';
        $income = $this->sumAmount(Income::class, $user);
        $expenses = $this->sumAmount(Expense::class, $user);
        $position = bcsub($income, $expenses, 4);

        return [
            'amount' => Money::of($position, $currency)->amount(),
            'currency' => $currency,
            'breakdown' => [
                'total_income' => Money::of($income, $currency)->amount(),
                'total_expenses' => Money::of($expenses, $currency)->amount(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function receivablesSummary(User $user): array
    {
        $currency = 'TND';
        $total = '0.0000';
        $overdue = '0.0000';
        $today = new \DateTimeImmutable('today');

        /** @var list<Customer> $customers */
        $customers = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Customer::class, 'c')
            ->where('c.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getResult();

        foreach ($customers as $customer) {
            $balance = $this->receivablesService->balance($user, $customer);
            $total = bcadd($total, $balance['amount'], 4);
        }

        $overdueResult = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(d.grandTotalAmount - d.amountPaid), 0)')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->andWhere('d.status NOT IN (:closed)')
            ->andWhere('d.dueDate < :today')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('type', DocumentType::Invoice)
            ->setParameter('closed', [InvoiceStatus::Paid->value, InvoiceStatus::Cancelled->value, InvoiceStatus::Credited->value])
            ->setParameter('today', $today->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();

        $overdue = Money::of((string) $overdueResult, $currency)->amount();

        return [
            'total_receivable' => ['amount' => Money::of($total, $currency)->amount(), 'currency' => $currency],
            'overdue_receivable' => ['amount' => $overdue, 'currency' => $currency],
        ];
    }

    /** @return array<string, mixed> */
    public function payablesSummary(User $user): array
    {
        $currency = 'TND';

        $issued = (string) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(i.totalAmount), 0)')
            ->from(SupplierInvoice::class, 'i')
            ->where('i.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getSingleScalarResult();

        $allocated = (string) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(a.allocatedAmount), 0)')
            ->from(SupplierPaymentAllocation::class, 'a')
            ->join('a.payment', 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getSingleScalarResult();

        $outstanding = bcsub($issued, $allocated, 4);

        return [
            'total_payable' => ['amount' => Money::of($outstanding, $currency)->amount(), 'currency' => $currency],
            'breakdown' => [
                'total_issued_invoices' => Money::of($issued, $currency)->amount(),
                'total_allocated_payments' => Money::of($allocated, $currency)->amount(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function agingSummary(User $user): array
    {
        $currency = 'TND';
        $today = new \DateTimeImmutable('today');
        $buckets = [
            'current' => '0.0000',
            '1_30_days' => '0.0000',
            '31_60_days' => '0.0000',
            '61_90_days' => '0.0000',
            'over_90_days' => '0.0000',
        ];

        /** @var list<CommercialDocument> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->andWhere('d.status NOT IN (:closed)')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('type', DocumentType::Invoice)
            ->setParameter('closed', [InvoiceStatus::Paid->value, InvoiceStatus::Cancelled->value, InvoiceStatus::Credited->value])
            ->getQuery()
            ->getResult();

        foreach ($invoices as $invoice) {
            $due = bcsub($invoice->getGrandTotal()->amount(), $invoice->getAmountPaid()->amount(), 4);

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

        return ['receivables_aging' => $buckets];
    }

    /** @param class-string $entityClass */
    private function sumAmount(string $entityClass, User $user): string
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(e.amount), 0)')
            ->from($entityClass, 'e')
            ->where('e.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getSingleScalarResult();

        return Money::of((string) $result, 'TND')->amount();
    }
}
