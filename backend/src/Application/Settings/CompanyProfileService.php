<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Application\Audit\AuditRecorder;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\Entity\Settings\CompanySetting;
use App\Infrastructure\Persistence\UnitOfWork;
use App\Infrastructure\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Seller details printed on generated documents (who is billing, how to pay them,
 * the stamp/signature image) and the stamp duty ("timbre fiscal") added to new invoices.
 *
 * Each field is a company_settings row. Documents read the profile when their PDF is
 * generated, so changes show up on PDFs generated afterwards.
 */
final class CompanyProfileService
{
    /** Text fields: API field name => setting key. */
    private const TEXT_FIELDS = [
        'name' => 'company.name',
        'phone' => 'company.phone',
        'email' => 'company.email',
        'tax_id' => 'company.tax_id',
        'address' => 'company.address',
        'bank_label' => 'invoice.bank_label',
        'bank_account' => 'invoice.bank_account',
    ];

    public const KEY_STAMP_DUTY = 'invoice.stamp_duty';
    private const KEY_STAMP_IMAGE = 'invoice.stamp_image';

    public const DEFAULT_BANK_LABEL = "Relevé d'identité postale";

    /** Tunisian stamp duty is levied in dinars, so it is only added to TND invoices. */
    public const STAMP_DUTY_CURRENCY = 'TND';

    public const MAX_STAMP_BYTES = 2 * 1024 * 1024;

    private const STAMP_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private AuditRecorder $auditRecorder,
        private DocumentStorage $storage,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(User $user): array
    {
        return $this->serialize($user->companyId());
    }

    /**
     * Replaces every field present in $fields; an empty value clears it.
     *
     * @param array<string, string|null> $fields
     *
     * @return array<string, mixed>
     */
    public function update(User $user, array $fields): array
    {
        $changes = [];

        foreach (self::TEXT_FIELDS as $field => $key) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $value = trim((string) $fields[$field]);

            if (mb_strlen($value) > 255) {
                throw new BadRequestHttpException(sprintf('%s must be 255 characters or fewer.', $field));
            }

            if ($field === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new BadRequestHttpException('email must be a valid email address.');
            }

            $changes[$key] = $value;
        }

        if (array_key_exists('stamp_duty', $fields)) {
            $changes[self::KEY_STAMP_DUTY] = self::normalizeStampDuty((string) $fields['stamp_duty']);
        }

        return $this->unitOfWork->transactional(function () use ($user, $changes): array {
            $companyId = $user->companyId();
            $actorId = EntityId::fromString($user->getId());

            foreach ($changes as $key => $value) {
                $this->write($companyId, $key, $value, $actorId);
            }

            $this->auditRecorder->record(
                action: 'settings.company_profile.updated',
                payload: ['fields' => array_keys($changes)],
                companyId: $companyId,
                actorUserId: $actorId,
                entityType: 'company_setting',
                entityId: $companyId,
                flush: false,
            );

            return $this->serialize($companyId);
        });
    }

    /** @return array<string, mixed> */
    public function uploadStamp(User $user, string $contents): array
    {
        if ($contents === '') {
            throw new UnprocessableEntityHttpException('The image is empty.');
        }

        if (strlen($contents) > self::MAX_STAMP_BYTES) {
            throw new UnprocessableEntityHttpException('The stamp image must be 2 MB or smaller.');
        }

        // Sniff the real type from the bytes; the client-supplied type and extension are not trusted.
        $info = @getimagesizefromstring($contents);
        $mimeType = is_array($info) ? $info['mime'] : null;

        if ($mimeType === null || !isset(self::STAMP_EXTENSIONS[$mimeType])) {
            throw new UnprocessableEntityHttpException('Upload a PNG or JPEG image.');
        }

        $companyId = $user->companyId();
        $key = sprintf('%s/branding/stamp-%s.%s', $companyId->toString(), EntityId::generate()->toString(), self::STAMP_EXTENSIONS[$mimeType]);
        $this->storage->store($key, $contents, $mimeType);
        $previous = $this->values($companyId)[self::KEY_STAMP_IMAGE] ?? null;

        $result = $this->unitOfWork->transactional(function () use ($user, $companyId, $key): array {
            $this->write($companyId, self::KEY_STAMP_IMAGE, $key, EntityId::fromString($user->getId()));

            return $this->serialize($companyId);
        });

        if ($previous !== null) {
            $this->storage->delete($previous);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function removeStamp(User $user): array
    {
        $companyId = $user->companyId();
        $previous = $this->values($companyId)[self::KEY_STAMP_IMAGE] ?? null;

        $result = $this->unitOfWork->transactional(function () use ($user, $companyId): array {
            $this->write($companyId, self::KEY_STAMP_IMAGE, '', EntityId::fromString($user->getId()));

            return $this->serialize($companyId);
        });

        if ($previous !== null) {
            $this->storage->delete($previous);
        }

        return $result;
    }

    public function profile(EntityId $companyId): CompanyProfile
    {
        $values = $this->values($companyId);
        $text = static fn (string $field): ?string => $values[self::TEXT_FIELDS[$field]] ?? null;

        return new CompanyProfile(
            name: $text('name'),
            phone: $text('phone'),
            email: $text('email'),
            taxId: $text('tax_id'),
            address: $text('address'),
            bankLabel: $text('bank_label') ?? self::DEFAULT_BANK_LABEL,
            bankAccount: $text('bank_account'),
            stampImage: $this->stampDataUri($values[self::KEY_STAMP_IMAGE] ?? null),
        );
    }

    /** Stamp duty to add to a new invoice in $currency (zero outside TND or when not configured). */
    public function stampDutyFor(EntityId $companyId, string $currency): Money
    {
        $currency = strtoupper($currency);

        if ($currency !== self::STAMP_DUTY_CURRENCY) {
            return Money::zero($currency);
        }

        return Money::of($this->values($companyId)[self::KEY_STAMP_DUTY] ?? '0', $currency);
    }

    /** Validates a non-negative amount with at most 4 decimals and returns it at scale 4. */
    public static function normalizeStampDuty(string $amount): string
    {
        $amount = trim($amount) === '' ? '0' : trim($amount);

        if (!preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            throw new BadRequestHttpException('stamp_duty must be a non-negative amount with at most 4 decimal places.');
        }

        if (bccomp($amount, '1000', 4) > 0) {
            throw new BadRequestHttpException('stamp_duty cannot exceed 1000.');
        }

        return bcadd($amount, '0', 4);
    }

    /** @return array<string, mixed> */
    private function serialize(EntityId $companyId): array
    {
        $profile = $this->profile($companyId);
        $values = $this->values($companyId);

        return [
            'name' => $profile->name,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'tax_id' => $profile->taxId,
            'address' => $profile->address,
            'bank_label' => $profile->bankLabel,
            'bank_account' => $profile->bankAccount,
            'stamp_duty' => $values[self::KEY_STAMP_DUTY] ?? '0.0000',
            'stamp_duty_currency' => self::STAMP_DUTY_CURRENCY,
            'stamp_image' => $profile->stampImage,
        ];
    }

    private function stampDataUri(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $contents = $this->storage->read($key);
        $info = $contents !== null ? @getimagesizefromstring($contents) : false;

        if ($contents === null || !is_array($info)) {
            return null;
        }

        return 'data:'.$info['mime'].';base64,'.base64_encode($contents);
    }

    /**
     * Non-empty profile settings of the company, keyed by setting key.
     *
     * @return array<string, string>
     */
    private function values(EntityId $companyId): array
    {
        /** @var list<CompanySetting> $settings */
        $settings = $this->entityManager->getRepository(CompanySetting::class)->findBy([
            'companyId' => $companyId->toString(),
            'key' => [...array_values(self::TEXT_FIELDS), self::KEY_STAMP_DUTY, self::KEY_STAMP_IMAGE],
        ]);
        $values = [];

        foreach ($settings as $setting) {
            if ($setting->getValue() !== '') {
                $values[$setting->getKey()] = $setting->getValue();
            }
        }

        return $values;
    }

    private function write(EntityId $companyId, string $key, string $value, EntityId $actorId): void
    {
        /** @var CompanySetting|null $setting */
        $setting = $this->entityManager->getRepository(CompanySetting::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'key' => $key,
        ]);

        if ($setting === null) {
            $this->entityManager->persist(new CompanySetting(EntityId::generate(), $companyId, $key, $value, $actorId));
            // Flush now so values() sees the row when serializing inside the same transaction.
            $this->entityManager->flush();

            return;
        }

        $setting->change($value, $actorId);
        $this->entityManager->flush();
    }
}
