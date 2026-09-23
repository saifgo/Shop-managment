<?php

declare(strict_types=1);

namespace App\Application\Customer;

use App\Application\Payments\CustomerReceivablesService;
use App\Application\Shared\PaginatedResult;
use App\Domain\Customer\CustomerType;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Catalog\CustomerPriceOverride;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Customer\Address;
use App\Infrastructure\Persistence\Entity\Customer\Contact;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Customer\CustomerIdentity;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CustomerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private CustomerReceivablesService $receivablesService,
    ) {}

    /**
     * @return PaginatedResult<array<string, mixed>>
     */
    public function list(User $user, int $page, int $perPage, ?string $search): PaginatedResult
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Customer::class, 'c')
            ->where('c.companyId = :companyId')
            ->setParameter('companyId', $user->companyId()->toString())
            ->orderBy('c.displayName', 'ASC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(c.displayName) LIKE :search OR LOWER(c.legalName) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        $qb->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $items = [];

        foreach ($paginator as $customer) {
            if ($customer instanceof Customer) {
                $items[] = $this->serializeCustomerSummary($customer);
            }
        }

        return new PaginatedResult($items, $page, $perPage, count($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $user, string $customerId): array
    {
        return $this->serializeCustomer360($this->findCustomer($user, $customerId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getOwnProfile(User $user): array
    {
        $portalUser = $this->findPortalUser($user);

        return $this->serializeCustomer360($portalUser->getCustomer(), portalView: true);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        $customer = new Customer(
            id: EntityId::generate(),
            companyId: $user->companyId(),
            type: CustomerType::from($data['type']),
            displayName: $data['display_name'],
            legalName: $data['legal_name'] ?? null,
            taxId: $data['tax_id'] ?? null,
            vatNumber: $data['vat_number'] ?? null,
            notes: $data['notes'] ?? null,
        );

        $this->entityManager->persist($customer);
        $this->persistNested($customer, $data);
        $this->unitOfWork->flush();

        return $this->serializeCustomer360($customer);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(User $user, string $customerId, array $data, bool $portalView = false): array
    {
        $customer = $this->findCustomer($user, $customerId, $portalView);

        if ($portalView) {
            $customer->update(
                type: $customer->getType(),
                displayName: $data['display_name'] ?? $customer->getDisplayName(),
                legalName: $customer->getLegalName(),
                taxId: $customer->getTaxId(),
                vatNumber: $customer->getVatNumber(),
                notes: $customer->getNotes(),
                isActive: $customer->isActive(),
            );
            $this->updatePortalContacts($customer, $data);
        } else {
            $customer->update(
                type: CustomerType::from($data['type']),
                displayName: $data['display_name'],
                legalName: $data['legal_name'] ?? null,
                taxId: $data['tax_id'] ?? null,
                vatNumber: $data['vat_number'] ?? null,
                notes: $data['notes'] ?? null,
                isActive: (bool) ($data['is_active'] ?? true),
            );
        }

        $this->unitOfWork->flush();

        return $this->serializeCustomer360($customer, $portalView);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createPriceOverride(User $user, string $customerId, array $data): array
    {
        $customer = $this->findCustomer($user, $customerId);

        /** @var ProductVariant|null $variant */
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $data['variant_id'],
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($variant === null) {
            throw new NotFoundHttpException('Variant not found.');
        }

        $existing = $this->entityManager->getRepository(CustomerPriceOverride::class)->findOneBy([
            'customer' => $customer,
            'variant' => $variant,
        ]);

        $price = Money::of($data['price']['amount'], $data['price']['currency']);
        $validFrom = isset($data['valid_from']) ? new \DateTimeImmutable($data['valid_from']) : null;
        $validUntil = isset($data['valid_until']) ? new \DateTimeImmutable($data['valid_until']) : null;

        if ($existing instanceof CustomerPriceOverride) {
            $existing->update($price, $validFrom, $validUntil);
            $override = $existing;
        } else {
            $override = new CustomerPriceOverride(
                id: EntityId::generate(),
                customer: $customer,
                variant: $variant,
                price: $price,
                validFrom: $validFrom,
                validUntil: $validUntil,
            );
            $this->entityManager->persist($override);
        }

        $this->unitOfWork->flush();

        return [
            'id' => $override->getId(),
            'customer_id' => $customer->getId(),
            'variant_id' => $variant->getId(),
            'variant_sku' => $variant->getSku(),
            'price' => [
                'amount' => $override->getPrice()->amount(),
                'currency' => $override->getPrice()->currency(),
            ],
            'valid_from' => $validFrom?->format(\DateTimeInterface::ATOM),
            'valid_until' => $validUntil?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orders(User $user, string $customerId): array
    {
        $this->findCustomer($user, $customerId);

        return [];
    }

    /**
     * @return array{amount: string, currency: string, breakdown?: array<string, string>}
     */
    public function balance(User $user, string $customerId): array
    {
        $customer = $this->findCustomer($user, $customerId);

        return $this->receivablesService->balance($user, $customer);
    }

    /** @return list<array<string, mixed>> */
    public function receivables(User $user, string $customerId): array
    {
        $this->findCustomer($user, $customerId);

        return $this->receivablesService->receivables($user, $customerId);
    }

    private function findCustomer(User $user, string $customerId, bool $portalView = false): Customer
    {
        if ($portalView) {
            return $this->findPortalUser($user)->getCustomer();
        }

        /** @var Customer|null $customer */
        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy([
            'id' => $customerId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($customer === null) {
            throw new NotFoundHttpException('Customer not found.');
        }

        return $customer;
    }

    private function findPortalUser(User $user): PortalUser
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        if ($portalUser === null) {
            throw new NotFoundHttpException('Portal account not linked to a customer.');
        }

        return $portalUser;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistNested(Customer $customer, array $data): void
    {
        foreach ($data['identities'] ?? [] as $identity) {
            $entity = new CustomerIdentity(
                id: EntityId::generate(),
                customer: $customer,
                type: $identity['type'],
                value: $identity['value'],
            );
            $this->entityManager->persist($entity);
        }

        foreach ($data['addresses'] ?? [] as $address) {
            $entity = new Address(
                id: EntityId::generate(),
                customer: $customer,
                type: $address['type'],
                line1: $address['line1'],
                city: $address['city'],
                postalCode: $address['postal_code'],
                country: $address['country'],
                line2: $address['line2'] ?? null,
                isDefault: (bool) ($address['is_default'] ?? false),
            );
            $this->entityManager->persist($entity);
        }

        foreach ($data['contacts'] ?? [] as $contact) {
            $entity = new Contact(
                id: EntityId::generate(),
                customer: $customer,
                name: $contact['name'],
                email: $contact['email'] ?? null,
                phone: $contact['phone'] ?? null,
                role: $contact['role'] ?? null,
                isPrimary: (bool) ($contact['is_primary'] ?? false),
            );
            $this->entityManager->persist($entity);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updatePortalContacts(Customer $customer, array $data): void
    {
        foreach ($data['contacts'] ?? [] as $contactData) {
            if (empty($contactData['id'])) {
                continue;
            }

            foreach ($customer->getContacts() as $contact) {
                if ($contact->getId() === $contactData['id']) {
                    $contact->update(
                        name: $contactData['name'] ?? $contact->getName(),
                        email: $contactData['email'] ?? $contact->getEmail(),
                        phone: $contactData['phone'] ?? $contact->getPhone(),
                        role: $contact->getRole(),
                        isPrimary: $contact->isPrimary(),
                    );
                }
            }
        }

        foreach ($data['addresses'] ?? [] as $addressData) {
            if (empty($addressData['id'])) {
                continue;
            }

            foreach ($customer->getAddresses() as $address) {
                if ($address->getId() === $addressData['id']) {
                    $address->update(
                        type: $address->getType(),
                        line1: $addressData['line1'] ?? $address->getLine1(),
                        line2: $addressData['line2'] ?? $address->getLine2(),
                        city: $addressData['city'] ?? $address->getCity(),
                        postalCode: $addressData['postal_code'] ?? $address->getPostalCode(),
                        country: $addressData['country'] ?? $address->getCountry(),
                        isDefault: $address->isDefault(),
                    );
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCustomerSummary(Customer $customer): array
    {
        return [
            'id' => $customer->getId(),
            'type' => $customer->getType()->value,
            'display_name' => $customer->getDisplayName(),
            'legal_name' => $customer->getLegalName(),
            'is_active' => $customer->isActive(),
            'portal_user_id' => $customer->getPortalUser()?->getUser()->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCustomer360(Customer $customer, bool $portalView = false): array
    {
        $data = $this->serializeCustomerSummary($customer);
        $data['tax_id'] = $customer->getTaxId();
        $data['vat_number'] = $customer->getVatNumber();
        $data['notes'] = $portalView ? null : $customer->getNotes();
        $data['identities'] = array_map(
            static fn(CustomerIdentity $i) => [
                'id' => $i->getId(),
                'type' => $i->getType(),
                'value' => $i->getValue(),
            ],
            $customer->getIdentities()->toArray(),
        );
        $data['addresses'] = array_map(
            static fn(Address $a) => [
                'id' => $a->getId(),
                'type' => $a->getType(),
                'line1' => $a->getLine1(),
                'line2' => $a->getLine2(),
                'city' => $a->getCity(),
                'postal_code' => $a->getPostalCode(),
                'country' => $a->getCountry(),
                'is_default' => $a->isDefault(),
            ],
            $customer->getAddresses()->toArray(),
        );
        $data['contacts'] = array_map(
            static fn(Contact $c) => [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'email' => $c->getEmail(),
                'phone' => $c->getPhone(),
                'role' => $c->getRole(),
                'is_primary' => $c->isPrimary(),
            ],
            $customer->getContacts()->toArray(),
        );

        if (!$portalView) {
            $data['price_overrides'] = array_map(
                static fn(CustomerPriceOverride $o) => [
                    'id' => $o->getId(),
                    'variant_id' => $o->getVariant()->getId(),
                    'variant_sku' => $o->getVariant()->getSku(),
                    'price' => [
                        'amount' => $o->getPrice()->amount(),
                        'currency' => $o->getPrice()->currency(),
                    ],
                ],
                $customer->getPriceOverrides()->toArray(),
            );
            $data['orders_count'] = 0;
            $data['balance'] = ['amount' => '0.0000', 'currency' => 'TND'];
        }

        return $data;
    }
}
