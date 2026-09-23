<?php

declare(strict_types=1);

namespace App\Tests\Domain\Production;

use App\Domain\Production\ReconciliationMode;
use App\Domain\Production\StageQuantityReconciler;
use App\Domain\Shared\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StageQuantityReconcilerTest extends TestCase
{
    private StageQuantityReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new StageQuantityReconciler();
    }

    public function testStrictReconciliationPassesWhenInputEqualsOutputPlusLoss(): void
    {
        $this->reconciler->reconcile(
            Quantity::of('100.0000'),
            Quantity::of('94.0000'),
            Quantity::of('6.0000'),
            ReconciliationMode::Strict,
        );

        self::assertTrue(true);
    }

    public function testStrictReconciliationFailsWhenQuantitiesDoNotMatch(): void
    {
        $this->expectException(\DomainException::class);
        $this->reconciler->reconcile(
            Quantity::of('100.0000'),
            Quantity::of('90.0000'),
            Quantity::of('6.0000'),
            ReconciliationMode::Strict,
        );
    }

    public function testFlexibleReconciliationAllowsUnaccountedLoss(): void
    {
        $this->reconciler->reconcile(
            Quantity::of('100.0000'),
            Quantity::of('90.0000'),
            Quantity::of('6.0000'),
            ReconciliationMode::Flexible,
        );

        self::assertTrue(true);
    }

    public function testFlexibleReconciliationFailsWhenSumExceedsInput(): void
    {
        $this->expectException(\DomainException::class);
        $this->reconciler->reconcile(
            Quantity::of('100.0000'),
            Quantity::of('95.0000'),
            Quantity::of('6.0000'),
            ReconciliationMode::Flexible,
        );
    }

    #[DataProvider('conversionCases')]
    public function testConversionModeAllowsTransformations(string $input, string $output, string $loss): void
    {
        $this->reconciler->reconcile(
            Quantity::of($input),
            Quantity::of($output),
            Quantity::of($loss),
            ReconciliationMode::Conversion,
        );

        self::assertTrue(true);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function conversionCases(): iterable
    {
        yield 'different totals' => ['100.0000', '80.0000', '5.0000'];
        yield 'output only' => ['50.0000', '48.0000', '0.0000'];
    }
}
