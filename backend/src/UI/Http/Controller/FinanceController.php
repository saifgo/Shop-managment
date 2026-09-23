<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Finance\FinanceProjectionService;
use App\Application\Finance\FinanceService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Finance')]
final class FinanceController extends AbstractController
{
    public function __construct(
        private FinanceService $financeService,
        private FinanceProjectionService $projectionService,
    ) {
    }

    #[Route('/api/finance/categories', name: 'api_finance_categories_list', methods: ['GET'])]
    public function listCategories(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json(['items' => $this->financeService->listCategories($user, $request->query->get('type'))]);
    }

    #[Route('/api/finance/categories', name: 'api_finance_categories_create', methods: ['POST'])]
    public function createCategory(#[MapRequestPayload] CreateFinanceCategoryRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_MANAGE);

        return $this->json($this->financeService->createCategory($user, [
            'type' => $payload->type,
            'code' => $payload->code,
            'name' => $payload->name,
        ]), 201);
    }

    #[Route('/api/finance/incomes', name: 'api_finance_incomes_list', methods: ['GET'])]
    public function listIncomes(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->financeService->listIncomes(
            $user,
            max(1, (int) $request->query->get('page', 1)),
            min(100, max(1, (int) $request->query->get('per_page', 20))),
        )->toArray());
    }

    #[Route('/api/finance/incomes', name: 'api_finance_incomes_create', methods: ['POST'])]
    public function recordIncome(#[MapRequestPayload] RecordIncomeRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_MANAGE);

        return $this->json($this->financeService->recordIncome($user, [
            'category_id' => $payload->category_id,
            'source' => $payload->source,
            'amount' => $payload->amount,
            'currency' => $payload->currency,
            'income_date' => $payload->income_date,
            'reference' => $payload->reference,
            'attachment_ref' => $payload->attachment_ref,
            'notes' => $payload->notes,
        ]), 201);
    }

    #[Route('/api/finance/expenses', name: 'api_finance_expenses_list', methods: ['GET'])]
    public function listExpenses(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->financeService->listExpenses(
            $user,
            max(1, (int) $request->query->get('page', 1)),
            min(100, max(1, (int) $request->query->get('per_page', 20))),
        )->toArray());
    }

    #[Route('/api/finance/expenses', name: 'api_finance_expenses_create', methods: ['POST'])]
    public function recordExpense(#[MapRequestPayload] RecordExpenseRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_MANAGE);

        return $this->json($this->financeService->recordExpense($user, [
            'category_id' => $payload->category_id,
            'payee' => $payload->payee,
            'amount' => $payload->amount,
            'currency' => $payload->currency,
            'expense_date' => $payload->expense_date,
            'supplier_id' => $payload->supplier_id,
            'attachment_ref' => $payload->attachment_ref,
            'notes' => $payload->notes,
        ]), 201);
    }

    #[Route('/api/finance/scheduled-transactions', name: 'api_finance_scheduled_list', methods: ['GET'])]
    public function listScheduled(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->financeService->listScheduledTransactions(
            $user,
            max(1, (int) $request->query->get('page', 1)),
            min(100, max(1, (int) $request->query->get('per_page', 20))),
        )->toArray());
    }

    #[Route('/api/finance/scheduled-transactions', name: 'api_finance_scheduled_create', methods: ['POST'])]
    public function createScheduled(#[MapRequestPayload] CreateScheduledTransactionRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_MANAGE);

        return $this->json($this->financeService->createScheduledTransaction($user, [
            'type' => $payload->type,
            'category_id' => $payload->category_id,
            'description' => $payload->description,
            'amount' => $payload->amount,
            'currency' => $payload->currency,
            'recurrence' => $payload->recurrence,
            'next_run_at' => $payload->next_run_at,
            'attachment_ref' => $payload->attachment_ref,
        ]), 201);
    }

    #[Route('/api/finance/projections/cash-position', name: 'api_finance_cash_position', methods: ['GET'])]
    public function cashPosition(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->projectionService->cashPosition($user));
    }

    #[Route('/api/finance/projections/receivables', name: 'api_finance_receivables_summary', methods: ['GET'])]
    public function receivablesSummary(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->projectionService->receivablesSummary($user));
    }

    #[Route('/api/finance/projections/payables', name: 'api_finance_payables_summary', methods: ['GET'])]
    public function payablesSummary(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->projectionService->payablesSummary($user));
    }

    #[Route('/api/finance/projections/aging', name: 'api_finance_aging_summary', methods: ['GET'])]
    public function agingSummary(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->projectionService->agingSummary($user));
    }
}

final readonly class CreateFinanceCategoryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['INCOME', 'EXPENSE'])]
        public string $type,
        #[Assert\NotBlank]
        public string $code,
        #[Assert\NotBlank]
        public string $name,
    ) {
    }
}

final readonly class RecordIncomeRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $category_id,
        #[Assert\NotBlank]
        public string $source,
        #[Assert\NotBlank]
        public string $amount,
        #[Assert\NotBlank]
        public string $currency,
        #[Assert\NotBlank]
        public string $income_date,
        public ?string $reference = null,
        public ?string $attachment_ref = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class RecordExpenseRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $category_id,
        #[Assert\NotBlank]
        public string $payee,
        #[Assert\NotBlank]
        public string $amount,
        #[Assert\NotBlank]
        public string $currency,
        #[Assert\NotBlank]
        public string $expense_date,
        public ?string $supplier_id = null,
        public ?string $attachment_ref = null,
        public ?string $notes = null,
    ) {
    }
}

final readonly class CreateScheduledTransactionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['INCOME', 'EXPENSE'])]
        public string $type,
        #[Assert\NotBlank]
        public string $category_id,
        #[Assert\NotBlank]
        public string $description,
        #[Assert\NotBlank]
        public string $amount,
        #[Assert\NotBlank]
        public string $currency,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY'])]
        public string $recurrence,
        #[Assert\NotBlank]
        public string $next_run_at,
        public ?string $attachment_ref = null,
    ) {
    }
}
