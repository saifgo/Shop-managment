<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Domain\Production\ProductionStatus;
use App\Infrastructure\Persistence\Entity\Production\ProductionItem;
use App\Infrastructure\Persistence\Entity\Production\ProductionLoss;
use App\Infrastructure\Persistence\Entity\Production\StageExecution;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * How many of the pieces put into production come out as sellable ones, and where the rest go.
 *
 * The headline yield is end to end: good pieces out of the last stage over pieces put in, for
 * orders that completed in the period. Summing every stage's input and output together would
 * report a 95% kiln as a 95% workshop, hiding that seven 95% stages leave only 70% of the pieces.
 */
final class ProductionYieldService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     period: array{from: ?string, to: ?string},
     *     order_count: int,
     *     stage_count: int,
     *     input_total: string,
     *     output_total: string,
     *     loss_total: string,
     *     yield_pct: ?string,
     *     by_stage: list<array{stage: string, sequence: int, input: string, accepted: string, loss: string, loss_pct: ?string}>,
     *     by_reason: list<array{reason_code: string, reason_label: string, quantity: string}>
     * }
     */
    public function summary(string $companyId, ?string $from = null, ?string $to = null): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'COALESCE(SUM(i.plannedQuantity), 0) AS input_total',
                'COALESCE(SUM(i.acceptedOutputQuantity), 0) AS output_total',
                'COUNT(DISTINCT p.id) AS order_count',
            )
            ->from(ProductionItem::class, 'i')
            ->join('i.productionOrder', 'p')
            ->where('p.companyId = :companyId')
            ->andWhere('p.status = :completed')
            ->setParameter('companyId', $companyId)
            ->setParameter('completed', ProductionStatus::Completed->value);
        $this->applyDateRange($qb, 'p.completedAt', $from, $to);
        $totals = $qb->getQuery()->getSingleResult();

        $input = bcadd((string) ($totals['input_total'] ?? '0'), '0', 4);
        $output = bcadd((string) ($totals['output_total'] ?? '0'), '0', 4);

        $stageQb = $this->entityManager->createQueryBuilder()
            ->select(
                'st.name AS stage',
                'se.stageSequence AS sequence',
                'COALESCE(SUM(se.inputQuantity), 0) AS input_total',
                'COALESCE(SUM(se.acceptedOutputQuantity), 0) AS accepted_total',
                'COALESCE(SUM(se.lossQuantity), 0) AS loss_total',
                'COUNT(se.id) AS executions',
            )
            ->from(StageExecution::class, 'se')
            ->join('se.productionOrder', 'p')
            ->join('se.productionStage', 'st')
            ->where('p.companyId = :companyId')
            ->andWhere('se.completedAt IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->groupBy('st.name', 'se.stageSequence')
            ->orderBy('se.stageSequence', 'ASC');
        $this->applyDateRange($stageQb, 'se.completedAt', $from, $to);

        $byStage = [];
        $stageCount = 0;
        foreach ($stageQb->getQuery()->getResult() as $row) {
            $stageInput = bcadd((string) $row['input_total'], '0', 4);
            $stageLoss = bcadd((string) $row['loss_total'], '0', 4);
            $stageCount += (int) $row['executions'];
            $byStage[] = [
                'stage' => (string) $row['stage'],
                'sequence' => (int) $row['sequence'],
                'input' => $stageInput,
                'accepted' => bcadd((string) $row['accepted_total'], '0', 4),
                'loss' => $stageLoss,
                'loss_pct' => bccomp($stageInput, '0', 4) > 0 ? bcmul(bcdiv($stageLoss, $stageInput, 6), '100', 2) : null,
            ];
        }

        $reasonQb = $this->entityManager->createQueryBuilder()
            ->select('l.reasonCode AS code', 'MAX(lr.label) AS label', 'COALESCE(SUM(l.quantity), 0) AS quantity')
            ->from(ProductionLoss::class, 'l')
            ->leftJoin('l.lossReason', 'lr')
            ->join('l.stageExecution', 'se')
            ->join('se.productionOrder', 'p')
            ->where('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->groupBy('l.reasonCode')
            ->orderBy('quantity', 'DESC');
        $this->applyDateRange($reasonQb, 'l.createdAt', $from, $to);

        $byReason = [];
        foreach ($reasonQb->getQuery()->getResult() as $row) {
            $byReason[] = [
                'reason_code' => (string) $row['code'],
                'reason_label' => (string) ($row['label'] ?? ucfirst(strtolower(str_replace('_', ' ', (string) $row['code'])))),
                'quantity' => bcadd((string) $row['quantity'], '0', 4),
            ];
        }

        return [
            'period' => ['from' => $from, 'to' => $to],
            'order_count' => (int) ($totals['order_count'] ?? 0),
            'stage_count' => $stageCount,
            'input_total' => $input,
            'output_total' => $output,
            'loss_total' => bccomp($input, $output, 4) > 0 ? bcsub($input, $output, 4) : '0.0000',
            'yield_pct' => bccomp($input, '0', 4) > 0 ? bcmul(bcdiv($output, $input, 6), '100', 2) : null,
            'by_stage' => $byStage,
            'by_reason' => $byReason,
        ];
    }

    private function applyDateRange(QueryBuilder $qb, string $field, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $qb->andWhere($field.' >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }

        if ($to !== null && $to !== '') {
            $qb->andWhere($field.' <= :to')->setParameter('to', (new \DateTimeImmutable($to))->setTime(23, 59, 59));
        }
    }
}
