<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Customer\CustomerService;
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
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Customers')]
final class CustomerController extends AbstractController
{
    public function __construct(private CustomerService $customerService)
    {
    }

    #[Route('/api/customers', name: 'api_customers_list', methods: ['GET'])]
    #[OA\Get(path: '/api/customers', summary: 'List customers', security: [['Bearer' => []]])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_VIEW);

        $result = $this->customerService->list(
            user: $user,
            page: max(1, (int) $request->query->get('page', 1)),
            perPage: min(100, max(1, (int) $request->query->get('per_page', 20))),
            search: $request->query->get('search'),
        );

        return $this->json($result->toArray());
    }

    #[Route('/api/customers', name: 'api_customers_create', methods: ['POST'])]
    #[OA\Post(path: '/api/customers', summary: 'Create customer', security: [['Bearer' => []]])]
    public function create(#[MapRequestPayload] CreateCustomerRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_MANAGE);

        $customer = $this->customerService->create($user, [
            'type' => $payload->type,
            'display_name' => $payload->displayName,
            'legal_name' => $payload->legalName,
            'tax_id' => $payload->taxId,
            'vat_number' => $payload->vatNumber,
            'notes' => $payload->notes,
            'addresses' => $payload->addresses,
            'contacts' => $payload->contacts,
            'identities' => $payload->identities,
        ]);

        return $this->json($customer, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/customers/me', name: 'api_customers_me', methods: ['GET'], priority: 10)]
    #[OA\Get(path: '/api/customers/me', summary: 'Get own customer profile', security: [['Bearer' => []]])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ACCOUNT_VIEW);

        return $this->json($this->customerService->getOwnProfile($user));
    }

    #[Route('/api/customers/me', name: 'api_customers_me_update', methods: ['PATCH'], priority: 10)]
    #[OA\Patch(path: '/api/customers/me', summary: 'Update own customer profile', security: [['Bearer' => []]])]
    public function updateMe(#[MapRequestPayload] UpdatePortalProfileRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::PORTAL_ACCOUNT_VIEW);

        $profile = $this->customerService->getOwnProfile($user);
        $updated = $this->customerService->update($user, $profile['id'], [
            'display_name' => $payload->displayName,
            'contacts' => $payload->contacts,
            'addresses' => $payload->addresses,
        ], portalView: true);

        return $this->json($updated);
    }

    #[Route('/api/customers/{id}', name: 'api_customers_get', methods: ['GET'])]
    #[OA\Get(path: '/api/customers/{id}', summary: 'Get customer', security: [['Bearer' => []]])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_VIEW);

        return $this->json($this->customerService->get($user, $id));
    }

    #[Route('/api/customers/{id}', name: 'api_customers_update', methods: ['PATCH'])]
    #[OA\Patch(path: '/api/customers/{id}', summary: 'Update customer', security: [['Bearer' => []]])]
    public function update(string $id, #[MapRequestPayload] UpdateCustomerRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_MANAGE);

        $customer = $this->customerService->update($user, $id, [
            'type' => $payload->type,
            'display_name' => $payload->displayName,
            'legal_name' => $payload->legalName,
            'tax_id' => $payload->taxId,
            'vat_number' => $payload->vatNumber,
            'notes' => $payload->notes,
            'is_active' => $payload->isActive,
        ]);

        return $this->json($customer);
    }

    #[Route('/api/customers/{id}/orders', name: 'api_customers_orders', methods: ['GET'])]
    #[OA\Get(path: '/api/customers/{id}/orders', summary: 'List customer orders', security: [['Bearer' => []]])]
    public function orders(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_VIEW);

        return $this->json(['items' => $this->customerService->orders($user, $id)]);
    }

    #[Route('/api/customers/{id}/balance', name: 'api_customers_balance', methods: ['GET'])]
    #[OA\Get(path: '/api/customers/{id}/balance', summary: 'Get customer balance', security: [['Bearer' => []]])]
    public function balance(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_VIEW);

        return $this->json($this->customerService->balance($user, $id));
    }

    #[Route('/api/customers/{id}/receivables', name: 'api_customers_receivables', methods: ['GET'])]
    #[OA\Get(path: '/api/customers/{id}/receivables', summary: 'Get customer receivables', security: [['Bearer' => []]])]
    public function receivables(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::FINANCE_VIEW);

        return $this->json(['items' => $this->customerService->receivables($user, $id)]);
    }

    #[Route('/api/customers/{id}/price-overrides', name: 'api_customers_price_overrides', methods: ['POST'])]
    #[OA\Post(path: '/api/customers/{id}/price-overrides', summary: 'Create customer price override', security: [['Bearer' => []]])]
    public function createPriceOverride(string $id, #[MapRequestPayload] CreatePriceOverrideRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::CUSTOMERS_MANAGE);

        $override = $this->customerService->createPriceOverride($user, $id, [
            'variant_id' => $payload->variantId,
            'price' => ['amount' => $payload->priceAmount, 'currency' => $payload->priceCurrency],
            'valid_from' => $payload->validFrom,
            'valid_until' => $payload->validUntil,
        ]);

        return $this->json($override, JsonResponse::HTTP_CREATED);
    }
}

final readonly class CreateCustomerRequest
{
    /** @param list<array<string, mixed>> $addresses */
    /** @param list<array<string, mixed>> $contacts */
    /** @param list<array<string, mixed>> $identities */
    public function __construct(
        #[Assert\Choice(choices: ['person', 'company', 'association'])]
        public string $type,
        #[Assert\NotBlank]
        #[SerializedName('display_name')]
        public string $displayName,
        #[SerializedName('legal_name')]
        public ?string $legalName = null,
        #[SerializedName('tax_id')]
        public ?string $taxId = null,
        #[SerializedName('vat_number')]
        public ?string $vatNumber = null,
        public ?string $notes = null,
        public array $addresses = [],
        public array $contacts = [],
        public array $identities = [],
    ) {
    }
}

final readonly class UpdateCustomerRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['person', 'company', 'association'])]
        public string $type,
        #[Assert\NotBlank]
        #[SerializedName('display_name')]
        public string $displayName,
        #[SerializedName('legal_name')]
        public ?string $legalName = null,
        #[SerializedName('tax_id')]
        public ?string $taxId = null,
        #[SerializedName('vat_number')]
        public ?string $vatNumber = null,
        public ?string $notes = null,
        #[SerializedName('is_active')]
        public bool $isActive = true,
    ) {
    }
}

final readonly class UpdatePortalProfileRequest
{
    /** @param list<array<string, mixed>> $contacts */
    /** @param list<array<string, mixed>> $addresses */
    public function __construct(
        #[SerializedName('display_name')]
        public ?string $displayName = null,
        public array $contacts = [],
        public array $addresses = [],
    ) {
    }
}

final readonly class CreatePriceOverrideRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('variant_id')]
        public string $variantId,
        #[Assert\NotBlank]
        #[SerializedName('price_amount')]
        public string $priceAmount,
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        #[SerializedName('price_currency')]
        public string $priceCurrency,
        #[SerializedName('valid_from')]
        public ?string $validFrom = null,
        #[SerializedName('valid_until')]
        public ?string $validUntil = null,
    ) {
    }
}
