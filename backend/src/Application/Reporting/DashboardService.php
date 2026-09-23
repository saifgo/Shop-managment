<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Application\Finance\FinanceProjectionService;
use App\Application\Payments\CustomerReceivablesService;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Production\ProductionStatus;
use App\Domain\Sales\DeliveryStatus;
use App\Domain\Sales\OrderStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Production\ProductionOrder;
use App\Infrastructure\Persistence\Entity\Production\StageExecution;
use App\Infrastructure\Persistence\Entity\Sales\Delivery;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use App\Infrastructure\Persistence\Entity\Sales\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DashboardService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FinanceProjectionService $financeProjectionService,
        private CustomerReceivablesService $receivablesService,
    ) {}

    /** @return array<string, mixed> */
    public function adminSummary(User $user): array
    {
        $companyId = $user->companyId()->toString();
        $pendingOrders = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('statuses', [
                OrderStatus::Submitted->value,
                OrderStatus::Confirmed->value,
                OrderStatus::PartiallyAllocated->value,
                OrderStatus::ReadyToDeliver->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        $backorderDemand = (string) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(oi.quantityBackordered), 0)')
            ->from(OrderItem::class, 'oi')
            ->join('oi.order', 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.status NOT IN (:terminal)')
            ->setParameter('companyId', $companyId)
            ->setParameter('terminal', [OrderStatus::Cancelled->value, OrderStatus::Delivered->value])
            ->getQuery()
            ->getSingleScalarResult();

        $activeProduction = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ProductionOrder::class, 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('statuses', [
                ProductionStatus::Planned->value,
                ProductionStatus::InProgress->value,
                ProductionStatus::Paused->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        $yield = $this->productionYield($companyId);
        $pendingDeliveries = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Delivery::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.status NOT IN (:terminal)')
            ->setParameter('companyId', $companyId)
            ->setParameter('terminal', [DeliveryStatus::Delivered->value])
            ->getQuery()
            ->getSingleScalarResult();

        $receivables = $this->financeProjectionService->receivablesSummary($user);
        $lowStock = $this->countLowStock($companyId);

        return [
            'pending_orders' => $pendingOrders,
            'backorder_demand' => Money::of((string) $backorderDemand, 'TND')->amount(),
            'active_production' => $activeProduction,
            'production_yield_pct' => $yield,
            'pending_deliveries' => $pendingDeliveries,
            'total_receivable' => $receivables['total_receivable'],
            'overdue_receivable' => $receivables['overdue_receivable'],
            'low_stock_variants' => $lowStock,
        ];
    }

    /** @return array<string, mixed> */
    public function portalSummary(User $user): array
    {
        $customer = $this->resolvePortalCustomer($user);
        $customerId = $customer->getId();
        $companyId = $user->companyId()->toString();

        $activeOrders = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.customer = :customerId')
            ->andWhere('o.status NOT IN (:terminal)')
            ->setParameter('companyId', $companyId)
            ->setParameter('customerId', $customerId)
            ->setParameter('terminal', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->getQuery()
            ->getSingleScalarResult();

        $balance = $this->receivablesService->balance($user, $customer);

        /** @var list<CommercialDocument> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(CommercialDocument::class, 'd')
            ->where('d.companyId = :companyId')
            ->andWhere('d.customer = :customerId')
            ->andWhere('d.documentType = :type')
            ->setParameter('companyId', $companyId)
            ->setParameter('customerId', $customerId)
            ->setParameter('type', DocumentType::Invoice)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        /** @var list<Order> $recentOrders */
        $recentOrders = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.customer = :customerId')
            ->setParameter('companyId', $companyId)
            ->setParameter('customerId', $customerId)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        /** @var list<Delivery> $deliveries */
        $deliveries = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Delivery::class, 'd')
            ->join('d.order', 'o')
            ->where('o.companyId = :companyId')
            ->andWhere('o.customer = :customerId')
            ->setParameter('companyId', $companyId)
            ->setParameter('customerId', $customerId)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return [
            'active_orders' => $activeOrders,
            'outstanding_balance' => ['amount' => $balance['amount'], 'currency' => $balance['currency']],
            'recent_invoices' => array_map(static fn(CommercialDocument $invoice): array => [
                'id' => $invoice->getId(),
                'document_number' => $invoice->getDocumentNumber(),
                'status' => $invoice->getStatus(),
                'grand_total' => [
                    'amount' => $invoice->getGrandTotal()->amount(),
                    'currency' => $invoice->getGrandTotal()->currency(),
                ],
                'amount_due' => [
                    'amount' => bcsub($invoice->getGrandTotal()->amount(), $invoice->getAmountPaid()->amount(), 4),
                    'currency' => $invoice->getGrandTotal()->currency(),
                ],
                'is_posted' => $invoice->isPosted(),
                'issued_at' => $invoice->getIssuedAt()?->format(\DateTimeInterface::ATOM),
            ], $invoices),
            'recent_orders' => array_map(static fn(Order $order): array => [
                'id' => $order->getId(),
                'reference' => $order->getReference(),
                'status' => $order->getStatus()->value,
                'grand_total' => [
                    'amount' => $order->getGrandTotal()->amount(),
                    'currency' => $order->getGrandTotal()->currency(),
                ],
                'created_at' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $recentOrders),
            'delivery_status' => array_map(static fn(Delivery $delivery): array => [
                'id' => $delivery->getId(),
                'reference' => $delivery->getReference(),
                'status' => $delivery->getStatus()->value,
                'order_id' => $delivery->getOrder()->getId(),
                'order_reference' => $delivery->getOrder()->getReference(),
                'created_at' => $delivery->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'delivered_at' => $delivery->getDeliveredAt()?->format(\DateTimeInterface::ATOM),
            ], $deliveries),
        ];
    }

    private function productionYield(string $companyId): ?string
    {
        $totals = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(se.inputQuantity), 0) AS input_total, COALESCE(SUM(se.acceptedOutputQuantity), 0) AS output_total')
            ->from(StageExecution::class, 'se')
            ->join('se.productionOrder', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('se.completedAt IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleResult();

        $input = (string) ($totals['input_total'] ?? '0.0000');
        $output = (string) ($totals['output_total'] ?? '0.0000');

        if (bccomp($input, '0.0000', 4) <= 0) {
            return null;
        }

        return bcmul(bcdiv($output, $input, 6), '100', 2);
    }

    private function countLowStock(string $companyId): int
    {
        /** @var list<StockBalance> $balances */
        $balances = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(StockBalance::class, 'b')
            ->where('b.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();

        $count = 0;

        foreach ($balances as $balance) {
            if (bccomp($balance->getAvailableToSell()->amount(), '5.0000', 4) < 0) {
                ++$count;
            }
        }

        return $count;
    }

    private function resolvePortalCustomer(User $user): Customer
    {
        if (!$user->isPortalUser()) {
            throw new AccessDeniedHttpException('Portal dashboard is only available to portal users.');
        }

        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new AccessDeniedHttpException('No customer profile linked to this portal account.');
        }

        return $portalUser->getCustomer();
    }
}
