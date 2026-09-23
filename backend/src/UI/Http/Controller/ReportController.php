<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Reporting\ReportService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Reports')]
final class ReportController extends AbstractController
{
    public function __construct(private ReportService $reportService)
    {
    }

    #[Route('/api/reports/sales', name: 'api_reports_sales', methods: ['GET'])]
    public function sales(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->reportService->sales(
            $user,
            $request->query->get('from'),
            $request->query->get('to'),
        ));
    }

    #[Route('/api/reports/margin', name: 'api_reports_margin', methods: ['GET'])]
    public function margin(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->reportService->margin(
            $user,
            $request->query->get('from'),
            $request->query->get('to'),
        ));
    }

    #[Route('/api/reports/stock', name: 'api_reports_stock', methods: ['GET'])]
    public function stock(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::INVENTORY_VIEW);

        return $this->json($this->reportService->stock($user));
    }

    #[Route('/api/reports/production-yield', name: 'api_reports_production_yield', methods: ['GET'])]
    public function productionYield(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PRODUCTION_VIEW);

        return $this->json($this->reportService->productionYield(
            $user,
            $request->query->get('from'),
            $request->query->get('to'),
        ));
    }

    #[Route('/api/reports/receivables-aging', name: 'api_reports_receivables_aging', methods: ['GET'])]
    public function receivablesAging(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->reportService->receivablesAging($user));
    }

    #[Route('/api/reports/payables-aging', name: 'api_reports_payables_aging', methods: ['GET'])]
    public function payablesAging(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->reportService->payablesAging($user));
    }

    #[Route('/api/reports/invoiced-sales', name: 'api_reports_invoiced_sales', methods: ['GET'])]
    public function invoicedSales(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json($this->reportService->issuedInvoices(
            $user,
            $request->query->get('from'),
            $request->query->get('to'),
        ));
    }
}
