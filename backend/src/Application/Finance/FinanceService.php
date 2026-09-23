<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Application\Shared\PaginatedResult;
use App\Domain\Finance\FinanceCategoryType;
use App\Domain\Finance\RecurrenceFrequency;
use App\Domain\Finance\ScheduledTransactionType;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Finance\Expense;
use App\Infrastructure\Persistence\Entity\Finance\FinanceCategory;
use App\Infrastructure\Persistence\Entity\Finance\Income;
use App\Infrastructure\Persistence\Entity\Finance\ScheduledTransaction;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Purchasing\Supplier;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinanceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
    ) {
    }

    /**
     * @param array{type: string, code: string, name: string} $payload
     *
     * @return array<string, mixed>
     */
    public function createCategory(User $user, array $payload): array
    {
        $category = new FinanceCategory(
            EntityId::generate(),
            $user->companyId(),
            FinanceCategoryType::from($payload['type']),
            $payload['code'],
            $payload['name'],
        );
        $this->entityManager->persist($category);

        return $this->serializeCategory($category);
    }

    /** @return list<array<string, mixed>> */
    public function listCategories(User $user, ?string $type = null): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(FinanceCategory::class, 'c')
            ->where('c.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('c.name', 'ASC');

        if ($type !== null) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }

        /** @var list<FinanceCategory> $categories */
        $categories = $qb->getQuery()->getResult();

        return array_map(fn (FinanceCategory $c) => $this->serializeCategory($c), $categories);
    }

    /**
     * @param array{category_id: string, source: string, amount: string, currency: string, income_date: string, reference?: string, attachment_ref?: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function recordIncome(User $user, array $payload): array
    {
        $category = $this->findCategory($user, $payload['category_id'], FinanceCategoryType::Income);
        $income = new Income(
            EntityId::generate(),
            $user->companyId(),
            $category,
            $payload['source'],
            Money::of($payload['amount'], $payload['currency']),
            new \DateTimeImmutable($payload['income_date']),
            $payload['reference'] ?? null,
            $payload['attachment_ref'] ?? null,
            $payload['notes'] ?? null,
            EntityId::fromString($user->getId()),
        );
        $this->entityManager->persist($income);

        return $this->serializeIncome($income);
    }

    /**
     * @param array{category_id: string, payee: string, amount: string, currency: string, expense_date: string, supplier_id?: string, attachment_ref?: string, notes?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function recordExpense(User $user, array $payload): array
    {
        $category = $this->findCategory($user, $payload['category_id'], FinanceCategoryType::Expense);
        $supplier = null;

        if (isset($payload['supplier_id'])) {
            $supplier = $this->findSupplier($user, $payload['supplier_id']);
        }

        $expense = new Expense(
            EntityId::generate(),
            $user->companyId(),
            $category,
            $payload['payee'],
            Money::of($payload['amount'], $payload['currency']),
            new \DateTimeImmutable($payload['expense_date']),
            $supplier,
            $payload['attachment_ref'] ?? null,
            $payload['notes'] ?? null,
            EntityId::fromString($user->getId()),
        );
        $this->entityManager->persist($expense);

        return $this->serializeExpense($expense);
    }

    /** @return PaginatedResult<array<string, mixed>> */
    public function listIncomes(User $user, int $page, int $perPage): PaginatedResult
    {
        return $this->paginateEntity(Income::class, $user, $page, $perPage, fn (Income $i) => $this->serializeIncome($i));
    }

    /** @return PaginatedResult<array<string, mixed>> */
    public function listExpenses(User $user, int $page, int $perPage): PaginatedResult
    {
        return $this->paginateEntity(Expense::class, $user, $page, $perPage, fn (Expense $e) => $this->serializeExpense($e));
    }

    /**
     * @param array{type: string, category_id: string, description: string, amount: string, currency: string, recurrence: string, next_run_at: string, attachment_ref?: string} $payload
     *
     * @return array<string, mixed>
     */
    public function createScheduledTransaction(User $user, array $payload): array
    {
        $type = ScheduledTransactionType::from($payload['type']);
        $expectedCategory = $type === ScheduledTransactionType::Income
            ? FinanceCategoryType::Income
            : FinanceCategoryType::Expense;
        $category = $this->findCategory($user, $payload['category_id'], $expectedCategory);

        $scheduled = new ScheduledTransaction(
            EntityId::generate(),
            $user->companyId(),
            $type,
            $category,
            $payload['description'],
            Money::of($payload['amount'], $payload['currency']),
            RecurrenceFrequency::from($payload['recurrence']),
            new \DateTimeImmutable($payload['next_run_at']),
            $payload['attachment_ref'] ?? null,
        );
        $this->entityManager->persist($scheduled);

        return $this->serializeScheduled($scheduled);
    }

    /** @return PaginatedResult<array<string, mixed>> */
    public function listScheduledTransactions(User $user, int $page, int $perPage): PaginatedResult
    {
        return $this->paginateEntity(
            ScheduledTransaction::class,
            $user,
            $page,
            $perPage,
            fn (ScheduledTransaction $s) => $this->serializeScheduled($s),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     * @param callable(T): array<string, mixed> $serializer
     *
     * @return PaginatedResult<array<string, mixed>>
     */
    private function paginateEntity(string $entityClass, User $user, int $page, int $perPage, callable $serializer): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($entityClass, 'e')
            ->where('e.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('e.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        $items = [];

        foreach ($qb->getQuery()->getResult() as $entity) {
            $items[] = $serializer($entity);
        }

        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->where('e.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->getQuery()
            ->getSingleScalarResult();

        return new PaginatedResult($items, $page, $perPage, $total);
    }

    private function findCategory(User $user, string $categoryId, FinanceCategoryType $expectedType): FinanceCategory
    {
        /** @var FinanceCategory|null $category */
        $category = $this->entityManager->getRepository(FinanceCategory::class)->findOneBy([
            'id' => $categoryId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($category === null) {
            throw new BadRequestHttpException('Finance category not found.');
        }

        if ($category->getType() !== $expectedType) {
            throw new BadRequestHttpException('Invalid category type for this transaction.');
        }

        return $category;
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

    /** @return array<string, mixed> */
    private function serializeCategory(FinanceCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'type' => $category->getType()->value,
            'code' => $category->getCode(),
            'name' => $category->getName(),
            'is_active' => $category->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeIncome(Income $income): array
    {
        return [
            'id' => $income->getId(),
            'category_id' => $income->getCategory()->getId(),
            'category_name' => $income->getCategory()->getName(),
            'source' => $income->getSource(),
            'amount' => ['amount' => $income->getAmount()->amount(), 'currency' => $income->getAmount()->currency()],
            'income_date' => $income->getIncomeDate()->format('Y-m-d'),
            'reference' => $income->getReference(),
            'attachment_ref' => $income->getAttachmentRef(),
            'notes' => $income->getNotes(),
            'created_at' => $income->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeExpense(Expense $expense): array
    {
        return [
            'id' => $expense->getId(),
            'category_id' => $expense->getCategory()->getId(),
            'category_name' => $expense->getCategory()->getName(),
            'supplier_id' => $expense->getSupplier()?->getId(),
            'payee' => $expense->getPayee(),
            'amount' => ['amount' => $expense->getAmount()->amount(), 'currency' => $expense->getAmount()->currency()],
            'expense_date' => $expense->getExpenseDate()->format('Y-m-d'),
            'attachment_ref' => $expense->getAttachmentRef(),
            'notes' => $expense->getNotes(),
            'created_at' => $expense->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeScheduled(ScheduledTransaction $scheduled): array
    {
        return [
            'id' => $scheduled->getId(),
            'type' => $scheduled->getType()->value,
            'category_id' => $scheduled->getCategory()->getId(),
            'category_name' => $scheduled->getCategory()->getName(),
            'description' => $scheduled->getDescription(),
            'amount' => ['amount' => $scheduled->getAmount()->amount(), 'currency' => $scheduled->getAmount()->currency()],
            'recurrence' => $scheduled->getRecurrence()->value,
            'next_run_at' => $scheduled->getNextRunAt()->format('Y-m-d'),
            'is_active' => $scheduled->isActive(),
            'attachment_ref' => $scheduled->getAttachmentRef(),
        ];
    }
}
