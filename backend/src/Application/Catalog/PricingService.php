<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\PricingResolver;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Catalog\CustomerPriceOverride;
use App\Infrastructure\Persistence\Entity\Catalog\PriceList;
use App\Infrastructure\Persistence\Entity\Catalog\PriceListItem;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use Doctrine\ORM\EntityManagerInterface;

final class PricingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PricingResolver $pricingResolver,
    ) {
    }

    /**
     * @return array{amount: string, currency: string, source: string, base_amount: string}
     */
    public function resolveForVariant(
        ProductVariant $variant,
        EntityId $companyId,
        ?EntityId $customerId = null,
    ): array {
        $basePrice = $variant->getBasePrice();
        $priceListPrice = $this->findPriceListPrice($variant, $companyId);
        $customerOverride = $customerId !== null
            ? $this->findCustomerOverride($variant, $customerId)
            : null;

        return $this->pricingResolver->resolve($basePrice, $priceListPrice, $customerOverride)->toArray();
    }

    private function findPriceListPrice(ProductVariant $variant, EntityId $companyId): ?Money
    {
        /** @var PriceList|null $priceList */
        $priceList = $this->entityManager->getRepository(PriceList::class)->findOneBy([
            'companyId' => $companyId->toString(),
            'isDefault' => true,
        ]);

        if ($priceList === null) {
            return null;
        }

        /** @var PriceListItem|null $item */
        $item = $this->entityManager->getRepository(PriceListItem::class)->findOneBy([
            'priceList' => $priceList,
            'variant' => $variant,
        ]);

        return $item?->getPrice();
    }

    private function findCustomerOverride(ProductVariant $variant, EntityId $customerId): ?Money
    {
        $customer = $this->entityManager->getReference(Customer::class, $customerId->toString());

        /** @var CustomerPriceOverride|null $override */
        $override = $this->entityManager->getRepository(CustomerPriceOverride::class)->findOneBy([
            'customer' => $customer,
            'variant' => $variant,
        ]);

        if ($override === null || !$override->isActiveAt(new \DateTimeImmutable())) {
            return null;
        }

        return $override->getPrice();
    }
}
