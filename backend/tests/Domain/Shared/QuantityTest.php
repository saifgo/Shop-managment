<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\Quantity;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testAddQuantities(): void
    {
        $a = Quantity::of('10', 'kg');
        $b = Quantity::of('2.5', 'kg');

        $this->assertSame('12.5000', $a->add($b)->amount());
    }

    public function testSubtractDoesNotGoNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Quantity::of('1', 'kg')->subtract(Quantity::of('2', 'kg'));
    }

    public function testRejectsNegativeInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Quantity::of('-1', 'kg');
    }

    public function testTimesScalesAndRoundsHalfUp(): void
    {
        self::assertSame('15.0000', Quantity::of('1.5')->times(Quantity::of('10'))->amount());
        // 0.3333 x 3 = 0.9999; 0.00005 rounds up.
        self::assertSame('0.9999', Quantity::of('0.3333')->times(Quantity::of('3'))->amount());
        self::assertSame('0.0001', Quantity::of('0.0001')->times(Quantity::of('0.5'))->amount());
        self::assertTrue(Quantity::of('0')->times(Quantity::of('7'))->isZero());
    }

    public function testZeroQuantity(): void
    {
        $this->assertTrue(Quantity::zero('unit')->isZero());
    }
}
