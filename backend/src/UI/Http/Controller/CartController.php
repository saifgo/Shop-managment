<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Sales\CartService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Cart')]
final class CartController extends AbstractController
{
    public function __construct(private CartService $cartService)
    {
    }

    #[Route('/api/cart/validate', name: 'api_cart_validate', methods: ['POST'])]
    #[OA\Post(path: '/api/cart/validate', summary: 'Validate cart pricing and availability', security: [['Bearer' => []]])]
    public function validate(#[MapRequestPayload] ValidateCartRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        if ($user->isPortalUser()) {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ORDERS_VIEW);
        } else {
            $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SALES_ORDERS_MANAGE);
        }

        return $this->json($this->cartService->validate($user, $payload->items, $payload->customerId));
    }
}

final readonly class ValidateCartRequest
{
    /**
     * @param list<array{variant_id: string, quantity: string}> $items
     */
    public function __construct(
        /** @var list<array{variant_id: string, quantity: string}> */
        #[Assert\Count(min: 1)]
        public array $items,
        #[SerializedName('customer_id')]
        public ?string $customerId = null,
    ) {
    }
}
