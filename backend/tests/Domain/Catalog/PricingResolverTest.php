<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog;

use App\Domain\Catalog\PricingResolver;
use App\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingResolverTest extends TestCase
{
    private PricingResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PricingResolver();
    }

    #[DataProvider('pricingCases')]
    public function testResolvesPriceWithCorrectPriority(
        ?Money $priceListPrice,
        ?Money $customerOverride,
        string $expectedAmount,
        string $expectedSource,
    ): void {
        $base = Money::of('100.0000', 'TND');
        $resolved = $this->resolver->resolve($base, $priceListPrice, $customerOverride);

        self::assertSame($expectedAmount, $resolved->price->amount());
        self::assertSame('TND', $resolved->price->currency());
        self::assertSame($expectedSource, $resolved->source);
        self::assertSame('100.0000', $resolved->basePrice->amount());
    }

    /**
     * @return iterable<string, array{0: ?Money, 1: ?Money, 2: string, 3: string}>
     */
    public static function pricingCases(): iterable
    {
        yield 'base price only' => [null, null, '100.0000', 'base'];
        yield 'price list overrides base' => [Money::of('90.0000', 'TND'), null, '90.0000', 'price_list'];
        yield 'customer override wins' => [Money::of('90.0000', 'TND'), Money::of('80.0000', 'TND'), '80.0000', 'customer_override'];
        yield 'customer override without price list' => [null, Money::of('75.0000', 'TND'), '75.0000', 'customer_override'];
    }
}
