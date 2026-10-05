<?php

declare(strict_types=1);

namespace App\Tests\Domain\Inventory;

use App\Domain\Catalog\BackorderPolicy;
use App\Domain\Catalog\ProductKind;
use App\Domain\Catalog\Visibility;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Catalog\Product;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockBalance;
use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use PHPUnit\Framework\TestCase;

final class StockBalanceCostTest extends TestCase
{
    public function testReceiptsBlendIntoAMovingAverage(): void
    {
        $balance = $this->balance();

        self::assertSame('2.5000', $balance->applyMovement('100', '0', '2.5'));
        self::assertSame('2.5000', $balance->getAverageCost());

        $balance->applyMovement('100', '0', '3.5');
        self::assertSame('3.0000', $balance->getAverageCost());
        self::assertSame('600.0000', $balance->getValue());
    }

    public function testIssuesLeaveAtTheAverageCostAndDoNotChangeIt(): void
    {
        $balance = $this->balance();
        $balance->applyMovement('10', '0', '4');

        self::assertSame('4.0000', $balance->applyMovement('-3', '0'));
        self::assertSame('4.0000', $balance->getAverageCost());
        self::assertSame('28.0000', $balance->getValue());
    }

    public function testReceiptWithoutACostComesInAtTheCurrentAverage(): void
    {
        $balance = $this->balance();
        $balance->applyMovement('10', '0', '4');

        self::assertSame('4.0000', $balance->applyMovement('5', '0'));
        self::assertSame('4.0000', $balance->getAverageCost());
    }

    public function testAnEmptiedBinTakesTheNextReceiptsCostWholesale(): void
    {
        $balance = $this->balance();
        $balance->applyMovement('10', '0', '4');
        $balance->applyMovement('-10', '0');
        $balance->applyMovement('5', '0', '7');

        self::assertSame('7.0000', $balance->getAverageCost());
    }

    public function testAveragesRoundHalfUp(): void
    {
        $balance = $this->balance();
        $balance->applyMovement('1', '0', '1');
        $balance->applyMovement('2', '0', '1.0001');

        // (1 + 2 x 1.0001) / 3 = 1.000067 -> 1.0001
        self::assertSame('1.0001', $balance->getAverageCost());
    }

    public function testRejectedMovementsLeaveTheCostUntouched(): void
    {
        $balance = $this->balance();
        $balance->applyMovement('2', '0', '5');

        try {
            $balance->applyMovement('-3', '0');
            self::fail('Stock cannot go negative.');
        } catch (\DomainException) {
        }

        self::assertSame('5.0000', $balance->getAverageCost());
        self::assertSame('2.0000', $balance->getPhysicalOnHand()->amount());
    }

    public function testLowStockPolicyDiffersForFinishedGoodsAndRawMaterials(): void
    {
        $vase = $this->variant(ProductKind::FinishedGood);
        $clay = $this->variant(ProductKind::RawMaterial);

        // Finished goods default to "under 5"; raw materials have no default.
        self::assertTrue($vase->isLowStock('4.0000', '5.0000'));
        self::assertFalse($vase->isLowStock('5.0000', '5.0000'));
        self::assertFalse($clay->isLowStock('0.0000', '5.0000'));

        // An explicit level triggers at or below it, for either kind.
        $clay->changeReorderLevel('50');
        self::assertTrue($clay->isLowStock('50.0000', '5.0000'));
        self::assertFalse($clay->isLowStock('50.0001', '5.0000'));
        self::assertSame('50.0000', $clay->getReorderLevel());
    }

    private function balance(): StockBalance
    {
        return new StockBalance(
            EntityId::generate(),
            EntityId::generate(),
            $this->variant(ProductKind::RawMaterial),
            new StockLocation(EntityId::generate(), EntityId::generate(), 'MAIN', 'Main', isDefault: true),
        );
    }

    private function variant(ProductKind $kind): ProductVariant
    {
        $companyId = EntityId::generate();
        $product = new Product(
            EntityId::generate(),
            $companyId,
            'Item',
            'item-'.bin2hex(random_bytes(3)),
            Visibility::Internal,
            BackorderPolicy::Deny,
            kind: $kind,
        );

        return new ProductVariant(EntityId::generate(), $companyId, $product, 'SKU-'.bin2hex(random_bytes(3)), 'Default', Money::of('0', 'TND'));
    }
}
