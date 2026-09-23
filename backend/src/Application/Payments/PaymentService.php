<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Shared\PaginatedResult;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Payments\Payment;
use App\Infrastructure\Persistence\Entity\Payments\PaymentAllocation;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PaymentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private CustomerReceivablesService $receivablesService,
    ) {
    }

    /**
     * @param array{customer_id: string, amount: string, currency: string, method: string, payment_date: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function record(User $user, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $payload, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                /** @var Payment|null $existing */
                $existing = $this->entityManager->getRepository(Payment::class)->findOneBy([
                    'companyId' => $user->companyId()->toString(),
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $this->serializePayment($existing);
                }
            }

            /** @var Customer|null $customer */
            $customer = $this->entityManager->getRepository(Customer::class)->findOneBy([
                'id' => $payload['customer_id'],
                'companyId' => $user->companyId()->toString(),
            ]);

            if ($customer === null) {
                throw new BadRequestHttpException('Customer not found.');
            }

            $payment = new Payment(
                EntityId::generate(),
                $user->companyId(),
                $this->generateReference($user->companyId()),
                $customer,
                Money::of($payload['amount'], $payload['currency']),
                PaymentMethod::from($payload['method']),
                new \DateTimeImmutable($payload['payment_date']),
                $payload['notes'] ?? null,
                $idempotencyKey,
                EntityId::fromString($user->getId()),
            );
            $this->entityManager->persist($payment);

            return $this->serializePayment($payment);
        });
    }

    /**
     * @param list<array{invoice_id: string, amount: string}> $allocations
     *
     * @return array<string, mixed>
     */
    public function allocate(User $user, string $paymentId, array $allocations): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $paymentId, $allocations): array {
            $payment = $this->findPayment($user, $paymentId);

            foreach ($allocations as $allocationPayload) {
                $invoice = $this->findIssuedInvoice($user, $allocationPayload['invoice_id']);
                $amount = Money::of($allocationPayload['amount'], $payment->getAmount()->currency());

                if ($payment->getCustomer()->getId() !== $invoice->getCustomer()->getId()) {
                    throw new BadRequestHttpException('Payment and invoice must belong to the same customer.');
                }

                if ($amount->compare($invoice->getAmountDue()) > 0) {
                    throw new BadRequestHttpException('Allocation exceeds invoice amount due.');
                }

                if ($amount->compare($payment->getUnallocatedAmount()) > 0) {
                    throw new BadRequestHttpException('Allocation exceeds unallocated payment amount.');
                }

                $allocation = new PaymentAllocation(
                    EntityId::generate(),
                    $payment,
                    $invoice,
                    $amount,
                );
                $payment->applyAllocation($amount);
                $invoice->applyPayment($amount);
                $this->entityManager->persist($allocation);
            }

            return $this->serializePayment($payment);
        });
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $paymentId): array
    {
        return $this->serializePayment($this->findPayment($user, $paymentId));
    }

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function list(User $user, int $page, int $perPage, ?string $customerId = null): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('p.createdAt', 'DESC');

        if ($customerId !== null) {
            $qb->andWhere('p.customer = :customer')->setParameter('customer', $customerId);
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))->setMaxResults($perPage);

        /** @var list<Payment> $payments */
        $payments = $qb->getQuery()->getResult();
        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getSingleScalarResult();

        $items = array_map(fn (Payment $payment) => $this->serializePayment($payment), $payments);

        return new PaginatedResult($items, $page, $perPage, $total);
    }

    private function findPayment(User $user, string $paymentId): Payment
    {
        /** @var Payment|null $payment */
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([
            'id' => $paymentId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($payment === null) {
            throw new NotFoundHttpException('Payment not found.');
        }

        return $payment;
    }

    private function findIssuedInvoice(User $user, string $invoiceId): CommercialDocument
    {
        /** @var CommercialDocument|null $invoice */
        $invoice = $this->entityManager->getRepository(CommercialDocument::class)->findOneBy([
            'id' => $invoiceId,
            'companyId' => $user->companyId()->toString(),
            'documentType' => DocumentType::Invoice,
        ]);

        if ($invoice === null || !$invoice->isPosted()) {
            throw new BadRequestHttpException('Issued invoice not found.');
        }

        if (in_array($invoice->getStatus(), [InvoiceStatus::Cancelled->value, InvoiceStatus::Credited->value], true)) {
            throw new BadRequestHttpException('Cannot allocate payment to cancelled or credited invoice.');
        }

        return $invoice;
    }

    private function generateReference(EntityId $companyId): string
    {
        $prefix = 'PAY-'.date('Ymd').'-';
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('p.reference LIKE :prefix')
            ->setParameter('companyId', $companyId->toString())
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%04d', $prefix, $count + 1);
    }

    /** @return array<string, mixed> */
    private function serializePayment(Payment $payment): array
    {
        $allocations = [];

        foreach ($payment->getAllocations() as $allocation) {
            $allocations[] = [
                'id' => $allocation->getId(),
                'invoice_id' => $allocation->getInvoice()->getId(),
                'invoice_number' => $allocation->getInvoice()->getDocumentNumber(),
                'allocated_amount' => [
                    'amount' => $allocation->getAllocatedAmount()->amount(),
                    'currency' => $payment->getAmount()->currency(),
                ],
            ];
        }

        return [
            'id' => $payment->getId(),
            'reference' => $payment->getReference(),
            'customer_id' => $payment->getCustomer()->getId(),
            'amount' => ['amount' => $payment->getAmount()->amount(), 'currency' => $payment->getAmount()->currency()],
            'allocated_amount' => ['amount' => $payment->getAllocatedAmount()->amount(), 'currency' => $payment->getAmount()->currency()],
            'unallocated_amount' => ['amount' => $payment->getUnallocatedAmount()->amount(), 'currency' => $payment->getAmount()->currency()],
            'method' => $payment->getMethod()->value,
            'status' => $payment->getStatus()->value,
            'payment_date' => $payment->getPaymentDate()->format('Y-m-d'),
            'notes' => $payment->getNotes(),
            'created_at' => $payment->getCreatedAt()->format(DATE_ATOM),
            'allocations' => $allocations,
        ];
    }
}
