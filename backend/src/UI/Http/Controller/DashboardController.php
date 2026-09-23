<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Reporting\DashboardService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(private DashboardService $dashboardService)
    {
    }

    #[Route('/api/dashboard/admin', name: 'api_dashboard_admin', methods: ['GET'])]
    public function admin(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_VIEW);

        return $this->json($this->dashboardService->adminSummary($user));
    }

    #[Route('/api/dashboard/portal', name: 'api_dashboard_portal', methods: ['GET'])]
    public function portal(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);

        return $this->json($this->dashboardService->portalSummary($user));
    }
}
