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

    public function testZeroQuantity(): void
    {
        $this->assertTrue(Quantity::zero('unit')->isZero());
    }
}
