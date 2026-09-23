<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddSameCurrency(): void
    {
        $a = Money::of('10.50', 'TND');
        $b = Money::of('2.25', 'TND');

        $result = $a->add($b);

        $this->assertSame('12.7500', $result->amount());
        $this->assertSame('TND', $result->currency());
    }

    public function testSubtractSameCurrency(): void
    {
        $a = Money::of('10.00', 'EUR');
        $b = Money::of('3.25', 'EUR');

        $this->assertSame('6.7500', $a->subtract($b)->amount());
    }

    public function testRejectsDifferentCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1.00', 'TND')->add(Money::of('1.00', 'EUR'));
    }

    #[DataProvider('invalidAmountProvider')]
    public function testRejectsInvalidAmount(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of($amount, 'TND');
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidAmountProvider(): array
    {
        return [
            ['abc'],
            ['12.34567'],
            [''],
        ];
    }

    public function testZeroAndNegativeChecks(): void
    {
        $zero = Money::zero('TND');
        $negative = Money::of('-1.0000', 'TND');

        $this->assertTrue($zero->isZero());
        $this->assertTrue($negative->isNegative());
    }
}
