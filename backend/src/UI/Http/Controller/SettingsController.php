<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Settings\CompanyProfileService;
use App\Application\Settings\TaxSettingsService;
use App\Domain\Identity\PermissionCatalog;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\PermissionVoter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Settings')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private TaxSettingsService $taxSettingsService,
        private CompanyProfileService $companyProfileService,
    ) {
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

    /** Staff only: the details printed on documents, and the stamp duty added to new invoices. */
    #[Route('/api/settings/company', name: 'api_settings_company_get', methods: ['GET'])]
    #[OA\Get(path: '/api/settings/company', summary: 'Get the company details printed on documents', security: [['Bearer' => []]])]
    public function getCompany(#[CurrentUser] User $user): JsonResponse
    {
        if ($user->isPortalUser()) {
            throw new AccessDeniedHttpException();
        }

        return $this->json($this->companyProfileService->get($user));
    }

    #[Route('/api/settings/company', name: 'api_settings_company_update', methods: ['PUT'])]
    #[OA\Put(path: '/api/settings/company', summary: 'Change the company details printed on documents and the invoice stamp duty', security: [['Bearer' => []]])]
    public function updateCompany(#[MapRequestPayload] UpdateCompanyProfileRequest $payload, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SYSTEM_SETTINGS_MANAGE);

        return $this->json($this->companyProfileService->update($user, [
            'name' => $payload->name,
            'phone' => $payload->phone,
            'email' => $payload->email,
            'tax_id' => $payload->taxId,
            'address' => $payload->address,
            'bank_label' => $payload->bankLabel,
            'bank_account' => $payload->bankAccount,
            'stamp_duty' => $payload->stampDuty !== null ? (string) $payload->stampDuty : null,
        ]));
    }

    #[Route('/api/settings/company/stamp', name: 'api_settings_company_stamp_upload', methods: ['POST'])]
    #[OA\Post(path: '/api/settings/company/stamp', summary: 'Upload the stamp/signature image printed on documents (multipart field "file")', security: [['Bearer' => []]])]
    public function uploadStamp(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SYSTEM_SETTINGS_MANAGE);

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new UnprocessableEntityHttpException(
                $file instanceof UploadedFile && \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                    ? 'The stamp image must be 2 MB or smaller.'
                    : 'Choose an image to upload.',
            );
        }

        return $this->json($this->companyProfileService->uploadStamp($user, (string) file_get_contents($file->getPathname())));
    }

    #[Route('/api/settings/company/stamp', name: 'api_settings_company_stamp_delete', methods: ['DELETE'])]
    #[OA\Delete(path: '/api/settings/company/stamp', summary: 'Remove the stamp/signature image', security: [['Bearer' => []]])]
    public function deleteStamp(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PermissionVoter::ATTRIBUTE, PermissionCatalog::SYSTEM_SETTINGS_MANAGE);

        return $this->json($this->companyProfileService->removeStamp($user));
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

/** Every field is replaced; send an empty string (or null) to clear one. */
final readonly class UpdateCompanyProfileRequest
{
    public function __construct(
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $email = null,
        #[SerializedName('tax_id')]
        public ?string $taxId = null,
        public ?string $address = null,
        #[SerializedName('bank_label')]
        public ?string $bankLabel = null,
        #[SerializedName('bank_account')]
        public ?string $bankAccount = null,
        #[SerializedName('stamp_duty')]
        public string|int|float|null $stampDuty = null,
    ) {
    }
}
