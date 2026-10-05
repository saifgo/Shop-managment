<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Tests\Support\AuthenticatedApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The pottery workshop's cost flow: clay and glaze are bought as raw materials, recipes say how
 * much each piece uses, production draws the materials when it starts, and the finished pieces
 * come out costed (materials plus kiln/labour, spread over the pieces that survived).
 */
final class WorkshopCostingTest extends AuthenticatedApiTestCase
{
    public function testRawMaterialsAreInternalAndNeverOrderableOrVisibleToCustomers(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');

        self::assertSame('raw_material', $clay['product']['kind']);
        self::assertSame('kg', $clay['product']['unit']);
        // Asked for "public": raw materials are forced internal.
        self::assertSame('internal', $clay['product']['visibility']);

        $customerId = $this->customerId($token);
        $this->request($token, 'POST', '/api/orders', [
            'customer_id' => $customerId,
            'items' => [['variant_id' => $clay['variant']['id'], 'quantity' => '1']],
        ], 400);

        $portalToken = $this->login('customer@tittawin.local')['access_token'];
        $names = array_column($this->request($portalToken, 'GET', '/api/products?per_page=100')['items'], 'name');
        self::assertNotContains('Stoneware clay', $names);
    }

    public function testPurchasesBlendIntoAMovingAverageCost(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');
        $this->buy($token, $clay['variant']['id'], '100', '2.5');
        $this->buy($token, $clay['variant']['id'], '100', '3.5');

        $stock = $this->request($token, 'GET', '/api/inventory/stock?kind=raw_material')['items'];
        self::assertCount(1, $stock);
        self::assertSame('200.0000', $stock[0]['physical_on_hand']);
        self::assertSame('3.0000', $stock[0]['average_cost']);
        self::assertSame('600.0000', $stock[0]['stock_value']);
        self::assertSame('kg', $stock[0]['unit']);

        $report = $this->request($token, 'GET', '/api/reports/stock');
        self::assertSame('600.0000', $report['raw_materials_value']);
    }

    public function testProductionDrawsRecipeMaterialsAndCostsTheFinishedPieces(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');
        $clayId = $clay['variant']['id'];
        $this->buy($token, $clayId, '100', '2.5');
        $this->buy($token, $clayId, '100', '3.5');

        $vase = $this->variantId('VAS-M');
        $recipe = $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', [
            'components' => [['component_variant_id' => $clayId, 'quantity_per_unit' => '1.5']],
        ]);
        self::assertSame('4.5000', $recipe['estimated_material_cost']);
        self::assertSame('kg', $recipe['components'][0]['unit']);

        $order = $this->request($token, 'POST', '/api/productions', [
            'items' => [['variant_id' => $vase, 'planned_quantity' => '10']],
            'plan' => true,
        ], 201);
        // Before it starts the order shows what it will need against what is on hand.
        self::assertSame('15.0000', $order['materials'][0]['quantity']);
        self::assertSame('200.0000', $order['materials'][0]['on_hand']);
        self::assertFalse($order['materials'][0]['is_short']);

        $this->request($token, 'PATCH', '/api/productions/'.$order['id'].'/costs', ['additional_cost' => '20'], 200);
        $started = $this->request($token, 'POST', '/api/productions/'.$order['id'].'/start', []);
        self::assertSame('45.0000', $started['material_cost']);
        self::assertTrue($started['materials'][0]['consumed']);
        self::assertSame('185.0000', $this->stock($token, $clayId)['physical_on_hand']);

        // Two of the ten pieces crack in the first firing.
        $done = $this->runAllStages($token, $started, ['First Oven' => ['accepted' => '8', 'loss' => '2']]);

        self::assertSame('COMPLETED', $done['status']);
        self::assertSame('8.0000', $done['items'][0]['accepted_output_quantity']);
        // (45 materials + 20 kiln/labour) / 8 pieces that survived.
        self::assertSame('8.1250', $done['items'][0]['unit_cost']);

        $receipt = $this->request($token, 'GET', '/api/inventory/movements?variant_id='.$vase.'&source_type=production_order');
        self::assertSame('PRODUCTION_RECEIPT', $receipt['items'][0]['movement_type']);
        self::assertSame('8.1250', $receipt['items'][0]['unit_cost']);

        $yield = $this->request($token, 'GET', '/api/reports/production-yield');
        self::assertSame('10.0000', $yield['input_total']);
        self::assertSame('8.0000', $yield['output_total']);
        self::assertSame('80.00', $yield['yield_pct']);
        $oven = array_values(array_filter($yield['by_stage'], static fn (array $row): bool => $row['stage'] === 'First Oven'));
        self::assertSame('2.0000', $oven[0]['loss']);
        self::assertSame('20.00', $oven[0]['loss_pct']);
        self::assertSame('Broken / unusable', $yield['by_reason'][0]['reason_label']);
    }

    public function testStartingWithoutEnoughMaterialIsRefusedAndConsumesNothing(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');
        $clayId = $clay['variant']['id'];
        $this->buy($token, $clayId, '10', '2');
        $vase = $this->variantId('VAS-M');
        $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', [
            'components' => [['component_variant_id' => $clayId, 'quantity_per_unit' => '1.5']],
        ]);

        $order = $this->request($token, 'POST', '/api/productions', [
            'items' => [['variant_id' => $vase, 'planned_quantity' => '10']],
        ], 201);
        self::assertTrue($order['materials'][0]['is_short']);

        $failed = $this->request($token, 'POST', '/api/productions/'.$order['id'].'/start', [], 400);
        self::assertStringContainsString('Not enough raw material', $failed['error']['message']);
        self::assertStringContainsString('Stoneware clay: need 15.0000 kg, have 10.0000', $failed['error']['message']);
        self::assertSame('10.0000', $this->stock($token, $clayId)['physical_on_hand']);
        self::assertSame('DRAFT', $this->request($token, 'GET', '/api/productions/'.$order['id'])['status']);
    }

    public function testCancellingBeforeWorkStartsGivesMaterialsBackButAfterwardsWritesThemOff(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');
        $clayId = $clay['variant']['id'];
        $this->buy($token, $clayId, '100', '2');
        $vase = $this->variantId('VAS-M');
        $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', [
            'components' => [['component_variant_id' => $clayId, 'quantity_per_unit' => '2']],
        ]);

        $first = $this->request($token, 'POST', '/api/productions', ['items' => [['variant_id' => $vase, 'planned_quantity' => '5']]], 201);
        $started = $this->request($token, 'POST', '/api/productions/'.$first['id'].'/start', []);
        self::assertSame('90.0000', $this->stock($token, $clayId)['physical_on_hand']);
        $cancelled = $this->request($token, 'POST', '/api/productions/'.$first['id'].'/cancel', ['reason' => 'Customer changed their mind']);
        self::assertSame('CANCELLED', $cancelled['status']);
        self::assertSame('100.0000', $this->stock($token, $clayId)['physical_on_hand']);
        self::assertSame('0.0000', $cancelled['material_cost']);
        self::assertSame([], $cancelled['materials']);
        self::assertNotEmpty($started['stages']);

        $second = $this->request($token, 'POST', '/api/productions', ['items' => [['variant_id' => $vase, 'planned_quantity' => '5']]], 201);
        $running = $this->request($token, 'POST', '/api/productions/'.$second['id'].'/start', []);
        $this->request($token, 'POST', '/api/productions/'.$second['id'].'/stages/'.$running['stages'][0]['id'].'/start', []);
        $this->request($token, 'POST', '/api/productions/'.$second['id'].'/cancel', ['reason' => 'Kiln broke']);
        // Clay that has been worked cannot be un-used.
        self::assertSame('90.0000', $this->stock($token, $clayId)['physical_on_hand']);
    }

    public function testRecipesOnlyAcceptRawMaterialsOfFinishedGoods(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');
        $vase = $this->variantId('VAS-M');
        $tagine = $this->variantId('TAG-M');

        $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', ['components' => [['component_variant_id' => $tagine, 'quantity_per_unit' => '1']]], 400);
        $this->request($token, 'PUT', '/api/variants/'.$clay['variant']['id'].'/recipe', ['components' => []], 400);
        $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', ['components' => [['component_variant_id' => $clay['variant']['id'], 'quantity_per_unit' => '0']]], 400);
        $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', ['components' => [
            ['component_variant_id' => $clay['variant']['id'], 'quantity_per_unit' => '1'],
            ['component_variant_id' => $clay['variant']['id'], 'quantity_per_unit' => '2'],
        ]], 400);

        $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', ['components' => [['component_variant_id' => $clay['variant']['id'], 'quantity_per_unit' => '1']]]);
        $cleared = $this->request($token, 'PUT', '/api/variants/'.$vase.'/recipe', ['components' => []]);
        self::assertSame([], $cleared['components']);
    }

    public function testReorderLevelFlagsRawMaterialsThatAreRunningLow(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg', reorderLevel: '50');
        $glaze = $this->createRawMaterial($token, 'White glaze', 'white-glaze', 'GLZ-WHT', 'kg');
        $this->buy($token, $clay['variant']['id'], '40', '2');
        $this->buy($token, $glaze['variant']['id'], '1', '9');

        $report = $this->request($token, 'GET', '/api/reports/stock');
        $bySku = [];
        foreach ($report['items'] as $item) {
            $bySku[$item['sku']] = $item;
        }

        // 40 kg is under its 50 kg level; the glaze has no level, so the default of 5 does not apply to it.
        self::assertTrue($bySku['CLAY-STO']['is_low_stock']);
        self::assertFalse($bySku['GLZ-WHT']['is_low_stock']);

        $dashboard = $this->request($token, 'GET', '/api/dashboard/admin');
        self::assertContains('CLAY-STO', array_column($dashboard['low_stock_items'], 'sku'));
    }

    public function testPurchaseOrderCanBeCancelledOnlyBeforeAnythingIsReceived(): void
    {
        $token = $this->login()['access_token'];
        $clay = $this->createRawMaterial($token, 'Stoneware clay', 'stoneware-clay', 'CLAY-STO', 'kg');
        $supplier = $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-A', 'name' => 'Argile Atlas'], 201);

        $cancelledPo = $this->purchaseOrder($token, $supplier['id'], $clay['variant']['id'], '10', '2');
        $cancelled = $this->request($token, 'POST', '/api/purchase-orders/'.$cancelledPo['id'].'/cancel', ['reason' => 'Supplier out of stock']);
        self::assertSame('CANCELLED', $cancelled['status']);
        $this->request($token, 'POST', '/api/purchase-orders/'.$cancelledPo['id'].'/receive', [
            'lines' => [['purchase_order_item_id' => $cancelledPo['items'][0]['id'], 'quantity' => '1']],
        ], 400);

        $partialPo = $this->purchaseOrder($token, $supplier['id'], $clay['variant']['id'], '10', '2');
        $this->request($token, 'POST', '/api/purchase-orders/'.$partialPo['id'].'/receive', [
            'lines' => [['purchase_order_item_id' => $partialPo['items'][0]['id'], 'quantity' => '4']],
        ], 201);
        $this->request($token, 'POST', '/api/purchase-orders/'.$partialPo['id'].'/cancel', [], 400);

        foreach (['0', 'abc', '7'] as $quantity) {
            $this->request($token, 'POST', '/api/purchase-orders/'.$partialPo['id'].'/receive', [
                'lines' => [['purchase_order_item_id' => $partialPo['items'][0]['id'], 'quantity' => $quantity]],
            ], 400);
        }
    }

    /**
     * @return array{product: array<string, mixed>, variant: array<string, mixed>}
     */
    private function createRawMaterial(string $token, string $name, string $slug, string $sku, string $unit, ?string $reorderLevel = null): array
    {
        $product = $this->request($token, 'POST', '/api/products', [
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'visibility' => 'public',
            'kind' => 'raw_material',
            'unit' => $unit,
        ], 201);
        $variant = $this->request($token, 'POST', '/api/products/'.$product['id'].'/variants', [
            'sku' => $sku,
            'name' => $name,
            'base_price_amount' => '0',
            'base_price_currency' => 'TND',
            'reorder_level' => $reorderLevel,
        ], 201);

        return ['product' => $product, 'variant' => $variant];
    }

    private function buy(string $token, string $variantId, string $quantity, string $unitPrice): void
    {
        $supplier = $this->supplierId($token);
        $po = $this->purchaseOrder($token, $supplier, $variantId, $quantity, $unitPrice);
        $this->request($token, 'POST', '/api/purchase-orders/'.$po['id'].'/receive', [
            'lines' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity' => $quantity]],
        ], 201);
    }

    /** @return array<string, mixed> */
    private function purchaseOrder(string $token, string $supplierId, string $variantId, string $quantity, string $unitPrice): array
    {
        return $this->request($token, 'POST', '/api/purchase-orders', [
            'supplier_id' => $supplierId,
            'currency' => 'TND',
            'items' => [['variant_id' => $variantId, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ], 201);
    }

    private function supplierId(string $token): string
    {
        $existing = $this->request($token, 'GET', '/api/suppliers?per_page=1')['items'];
        if ($existing !== []) {
            return $existing[0]['id'];
        }

        return $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-A', 'name' => 'Argile Atlas'], 201)['id'];
    }

    /** @return array<string, mixed> */
    private function stock(string $token, string $variantId): array
    {
        $items = $this->request($token, 'GET', '/api/inventory/stock?variant_id='.$variantId)['items'];
        self::assertCount(1, $items);

        return $items[0];
    }

    /**
     * @param array<string, mixed> $started
     * @param array<string, array{accepted: string, loss: string}> $outcomes by stage name; other stages pass everything through
     *
     * @return array<string, mixed>
     */
    private function runAllStages(string $token, array $started, array $outcomes): array
    {
        $order = $started;
        $base = '/api/productions/'.$started['id'];
        $remaining = $started['items'][0]['planned_quantity'];

        foreach ($started['stages'] as $stage) {
            $this->request($token, 'POST', $base.'/stages/'.$stage['id'].'/start', []);
            $outcome = $outcomes[$stage['name']] ?? ['accepted' => $remaining, 'loss' => '0'];
            $body = ['accepted_output_quantity' => $outcome['accepted'], 'loss_quantity' => $outcome['loss']];

            if ($outcome['loss'] !== '0') {
                $body['losses'] = [['reason_code' => 'BROKEN', 'quantity' => $outcome['loss']]];
            }

            $order = $this->request($token, 'POST', $base.'/stages/'.$stage['id'].'/complete', $body);
            $remaining = $outcome['accepted'];
        }

        return $order;
    }

    private function customerId(string $token): string
    {
        return $this->request($token, 'GET', '/api/customers?per_page=1')['items'][0]['id'];
    }

    private function variantId(string $sku): string
    {
        $variant = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ProductVariant::class)
            ->findOneBy(['sku' => $sku]);
        self::assertInstanceOf(ProductVariant::class, $variant);

        return $variant->getId();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $token, string $method, string $uri, ?array $body = null, int $expectedStatus = 200): array
    {
        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        );
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), $method.' '.$uri.' '.$client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }
}
