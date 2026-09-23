<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Payments\PaymentAllocation;
use Doctrine\ORM\EntityManagerInterface;

final class CustomerReceivablesService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /** @return array{amount: string, currency: string, breakdown: array<string, string>} */
    public function balance(User $user, Customer $customer): array
    {
        $currency = 'TND';
        $issued = $this->sumInvoices($user, $customer, DocumentType::Invoice);
        $credits = $this->sumInvoices($user, $customer, DocumentType::CreditNote);
        $allocated = $this->sumAllocations($user, $customer);
        $outstanding = bcsub(bcsub($issued, $allocated, 4), $credits, 4);

        return [
            'amount' => Money::of($outstanding, $currency)->amount(),
            'currency' => $currency,
            'breakdown' => [
                'total_issued_invoices' => $issued,
                'total_allocated_payments' => $allocated,
                'total_credit_notes' => $credits,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function receivables(User $user, string $customerId): array
    {
        /** @var list<CommercialDocument> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.customer = :customerId')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->andWhere('d.status NOT IN (:closed)')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('customerId', $customerId)
            ->setParameter('type', DocumentType::Invoice)
            ->setParameter('closed', [InvoiceStatus::Paid->value, InvoiceStatus::Cancelled->value, InvoiceStatus::Credited->value])
            ->orderBy('d.dueDate', 'ASC')
            ->getQuery()
            ->getResult();

        $items = [];

        foreach ($invoices as $invoice) {
            $items[] = [
                'invoice_id' => $invoice->getId(),
                'document_number' => $invoice->getDocumentNumber(),
                'status' => $invoice->getStatus(),
                'issued_at' => $invoice->getIssuedAt()?->format(DATE_ATOM),
                'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
                'grand_total' => ['amount' => $invoice->getGrandTotal()->amount(), 'currency' => $invoice->getCurrency()],
                'amount_paid' => ['amount' => $invoice->getAmountPaid()->amount(), 'currency' => $invoice->getCurrency()],
                'amount_due' => ['amount' => $invoice->getAmountDue()->amount(), 'currency' => $invoice->getCurrency()],
            ];
        }

        return $items;
    }

    private function sumInvoices(User $user, Customer $customer, DocumentType $type): string
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(d.grandTotalAmount), 0)')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.customer = :customer')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.isPosted = true')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('customer', $customer)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return Money::of((string) $result, 'TND')->amount();
    }

    private function sumAllocations(User $user, Customer $customer): string
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(a.allocatedAmount), 0)')
            ->from(PaymentAllocation::class, 'a')
            ->join('a.payment', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('p.customer = :customer')
            ->setParameter('companyId', $user->companyId()->toString())
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();

        return Money::of((string) $result, 'TND')->amount();
    }
}
