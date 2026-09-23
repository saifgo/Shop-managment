<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Sales\DemandQueryService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Demand')]
final class DemandController extends AbstractController
{
    public function __construct(private DemandQueryService $demandQueryService)
    {
    }

    #[Route('/api/demand/by-customer', name: 'api_demand_by_customer', methods: ['GET'])]
    #[OA\Get(path: '/api/demand/by-customer', summary: 'Demand grouped by customer', security: [['Bearer' => []]])]
    public function byCustomer(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_VIEW);

        return $this->json($this->demandQueryService->byCustomer(
            $user,
            $request->query->get('customer_id'),
            $request->query->get('variant_id'),
        ));
    }

    #[Route('/api/demand/by-product', name: 'api_demand_by_product', methods: ['GET'])]
    #[OA\Get(path: '/api/demand/by-product', summary: 'Demand grouped by product', security: [['Bearer' => []]])]
    public function byProduct(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_VIEW);

        return $this->json($this->demandQueryService->byProduct(
            $user,
            $request->query->get('variant_id'),
        ));
    }
}
