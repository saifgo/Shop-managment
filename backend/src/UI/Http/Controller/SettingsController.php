<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Settings\TaxSettingsService;
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

#[OA\Tag(name: 'Settings')]
final class SettingsController extends AbstractController
{
    public function __construct(private TaxSettingsService $taxSettingsService)
    {
    }

    /** Readable by any signed-in user: carts, checkout and document forms all need the current rate. */
    #[Route('/api/settings/tax', name: 'api_settings_tax_get', methods: ['GET'])]
    #[OA\Get(path: '/api/settings/tax', summary: 'Get the default tax rate (percentage) applied to new orders and document lines', security: [['Bearer' => []]])]
    public function getTax(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->taxSettingsService->get($user));
    }

    #[Route('/api/settings/tax', name: 'api_settings_tax_update', methods: ['PUT'])]
    #[OA\Put(path: '/api/settings/tax', summary: 'Change the default tax rate; existing orders and documents keep the rate they were created with', security: [['Bearer' => []]])]
    public function updateTax(#[MapRequestPayload] UpdateTaxSettingsRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SYSTEM_SETTINGS_MANAGE);

        return $this->json($this->taxSettingsService->update($user, (string) $payload->defaultTaxRate));
    }
}

final readonly class UpdateTaxSettingsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('default_tax_rate')]
        public string|int|float $defaultTaxRate,
    ) {
    }
}
