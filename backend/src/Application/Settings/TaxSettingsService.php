<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Application\Audit\AuditRecorder;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Settings\CompanySetting;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Company-wide default VAT rate. Rates are percentages at scale 4 ("20.0000" = 20%),
 * the same convention as order items and document lines.
 *
 * The rate is read when a cart is priced or a manual line is created and copied onto
 * the order item / document line, so changing it only affects orders and documents
 * created afterwards.
 */
final class TaxSettingsService
{
    public const KEY_DEFAULT_TAX_RATE = 'tax.default_rate';

    /** Used until an administrator saves a rate for the company. */
    public const FALLBACK_TAX_RATE_PERCENT = '20.0000';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private AuditRecorder $auditRecorder,
    ) {
    }

    public function defaultTaxRate(EntityId $companyId): string
    {
        return $this->findSetting($companyId)?->getValue() ?? self::FALLBACK_TAX_RATE_PERCENT;
    }

    /** @return array{default_tax_rate: string, updated_at: string|null} */
    public function get(User $user): array
    {
        return $this->serialize($this->findSetting($user->companyId()));
    }

    /** @return array{default_tax_rate: string, updated_at: string|null} */
    public function update(User $user, string $rate): array
    {
        $rate = self::normalizeRate($rate);

        return $this->unitOfWork->transactional(function () use ($user, $rate): array {
            $companyId = $user->companyId();
            $actorId = EntityId::fromString($user->getId());
            $setting = $this->findSetting($companyId);
            $previous = $setting?->getValue() ?? self::FALLBACK_TAX_RATE_PERCENT;

            if ($setting === null) {
                $setting = new CompanySetting(EntityId::generate(), $companyId, self::KEY_DEFAULT_TAX_RATE, $rate, $actorId);
                $this->entityManager->persist($setting);
            } else {
                $setting->change($rate, $actorId);
            }

            $this->auditRecorder->record(
                action: 'settings.tax_rate.updated',
                payload: ['from' => $previous, 'to' => $rate],
                companyId: $companyId,
                actorUserId: $actorId,
                entityType: 'company_setting',
                entityId: EntityId::fromString($setting->getId()),
                flush: false,
            );

            return $this->serialize($setting);
        });
    }

    /** Validates a percentage between 0 and 100 with at most 4 decimals and returns it at scale 4. */
    public static function normalizeRate(string $rate): string
    {
        $rate = trim($rate);

        if (!preg_match('/^\d+(\.\d{1,4})?$/', $rate)) {
            throw new BadRequestHttpException('tax_rate must be a non-negative percentage with at most 4 decimal places.');
        }

        if (bccomp($rate, '100', 4) > 0) {
            throw new BadRequestHttpException('tax_rate is a percentage and cannot exceed 100.');
        }

        return bcadd($rate, '0', 4);
    }

    /** @return array{default_tax_rate: string, updated_at: string|null} */
    private function serialize(?CompanySetting $setting): array
    {
        return [
            'default_tax_rate' => $setting?->getValue() ?? self::FALLBACK_TAX_RATE_PERCENT,
            'updated_at' => $setting?->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function findSetting(EntityId $companyId): ?CompanySetting
    {
        /** @var CompanySetting|null $setting */
        $setting = $this->entityManager->getRepository(CompanySetting::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'key' => self::KEY_DEFAULT_TAX_RATE,
        ]);

        return $setting;
    }
}
