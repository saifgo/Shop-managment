<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Money;

/**
 * Resolves effective unit price: customer override > price list > variant base price.
 */
final class PricingResolver
{
    public function resolve(
        Money $basePrice,
        ?Money $priceListPrice,
        ?Money $customerOverride,
    ): ResolvedPrice {
        if ($customerOverride !== null) {
            return new ResolvedPrice(
                price: $customerOverride,
                source: 'customer_override',
                basePrice: $basePrice,
            );
        }

        if ($priceListPrice !== null) {
            return new ResolvedPrice(
                price: $priceListPrice,
                source: 'price_list',
                basePrice: $basePrice,
            );
        }

        return new ResolvedPrice(
            price: $basePrice,
            source: 'base',
            basePrice: $basePrice,
        );
    }
}

final readonly class ResolvedPrice
{
    public function __construct(
        public Money $price,
        public string $source,
        public Money $basePrice,
    ) {
    }

    /**
     * @return array{amount: string, currency: string, source: string, base_amount: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->price->amount(),
            'currency' => $this->price->currency(),
            'source' => $this->source,
            'base_amount' => $this->basePrice->amount(),
        ];
    }
}
