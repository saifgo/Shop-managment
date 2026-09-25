<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class ProductionTest extends AuthenticatedApiTestCase
{
    public function testProductionAdvancesStagesWithLosses(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');
        $production = $this->createProduction($admin['access_token'], $variantId, '10.0000');
        $this->startProduction($admin['access_token'], $production['id']);

        $detail = $this->getProduction($admin['access_token'], $production['id']);
        $stage = $detail['stages'][0];
        $this->startStage($admin['access_token'], $production['id'], $stage['id'], '10.0000');
        $this->completeStage($admin['access_token'], $production['id'], $stage['id'], '9.0000', '1.0000');

        $updated = $this->getProduction($admin['access_token'], $production['id']);
        self::assertSame('1.0000', $updated['stages'][0]['loss_quantity']);
    }

    public function testProductionWithSeveralProductsTracksEachProductAndPostsStockPerProduct(): void
    {
        $admin = $this->login();
        $token = $admin['access_token'];
        $vaseId = $this->findVariantIdBySku($token, 'VAS-M');
        $bowlId = $this->findVariantIdBySku($token, 'BWL-4');
        $vaseStockBefore = $this->getStockForVariant($token, $vaseId)['physical_on_hand'];
        $bowlStockBefore = $this->getStockForVariant($token, $bowlId)['physical_on_hand'];

        $production = $this->postJson($token, '/api/productions', [
            'items' => [
                ['variant_id' => $vaseId, 'planned_quantity' => '6'],
                ['variant_id' => $bowlId, 'planned_quantity' => '4'],
            ],
        ], 201);
        self::assertSame(2, $production['item_count']);
        self::assertSame('10.0000', $production['planned_quantity']);

        $itemIds = array_column($production['items'], 'id', 'variant_id');
        $this->startProduction($token, $production['id']);
        $detail = $this->getProduction($token, $production['id']);

        // Lose one vase in the first stage; everything else passes through.
        $remaining = [$itemIds[$vaseId] => '6.0000', $itemIds[$bowlId] => '4.0000'];
        foreach ($detail['stages'] as $index => $stage) {
            $started = $this->postJson($token, '/api/productions/'.$production['id'].'/stages/'.$stage['id'].'/start', [], 200);
            $lines = array_column($started['stages'][$index]['lines'], 'input_quantity', 'item_id');
            $expected = $remaining;
            ksort($expected);
            ksort($lines);
            self::assertSame($expected, $lines);

            $results = [];
            foreach ($remaining as $itemId => $input) {
                $loss = $index === 0 && $itemId === $itemIds[$vaseId] ? '1.0000' : '0.0000';
                $accepted = bcsub($input, $loss, 4);
                $results[] = [
                    'item_id' => $itemId,
                    'accepted_output_quantity' => $accepted,
                    'loss_quantity' => $loss,
                    'losses' => $loss === '0.0000' ? [] : [['reason_code' => 'CRACKS', 'quantity' => $loss]],
                ];
                $remaining[$itemId] = $accepted;
            }

            $this->postJson($token, '/api/productions/'.$production['id'].'/stages/'.$stage['id'].'/complete', ['items' => $results], 200);
        }

        $completed = $this->getProduction($token, $production['id']);
        self::assertSame('COMPLETED', $completed['status']);
        $byVariant = array_column($completed['items'], null, 'variant_id');
        self::assertSame('5.0000', $byVariant[$vaseId]['accepted_output_quantity']);
        self::assertSame('1.0000', $byVariant[$vaseId]['loss_quantity']);
        self::assertSame('4.0000', $byVariant[$bowlId]['accepted_output_quantity']);

        self::assertSame(bcadd($vaseStockBefore, '5', 4), $this->getStockForVariant($token, $vaseId)['physical_on_hand']);
        self::assertSame(bcadd($bowlStockBefore, '4', 4), $this->getStockForVariant($token, $bowlId)['physical_on_hand']);
    }

    public function testMultiProductStageRequiresResultsForEveryProduct(): void
    {
        $admin = $this->login();
        $token = $admin['access_token'];
        $vaseId = $this->findVariantIdBySku($token, 'VAS-M');
        $bowlId = $this->findVariantIdBySku($token, 'BWL-4');

        $production = $this->postJson($token, '/api/productions', [
            'items' => [
                ['variant_id' => $vaseId, 'planned_quantity' => '2'],
                ['variant_id' => $bowlId, 'planned_quantity' => '3'],
            ],
        ], 201);
        $this->startProduction($token, $production['id']);
        $stageId = $this->getProduction($token, $production['id'])['stages'][0]['id'];
        $this->postJson($token, '/api/productions/'.$production['id'].'/stages/'.$stageId.'/start', [], 200);

        $vaseItemId = array_column($production['items'], 'id', 'variant_id')[$vaseId];
        $this->postJson($token, '/api/productions/'.$production['id'].'/stages/'.$stageId.'/complete', [
            'items' => [['item_id' => $vaseItemId, 'accepted_output_quantity' => '2', 'loss_quantity' => '0']],
        ], 400);

        $this->postJson($token, '/api/productions', [
            'items' => [
                ['variant_id' => $vaseId, 'planned_quantity' => '1'],
                ['variant_id' => $vaseId, 'planned_quantity' => '2'],
            ],
        ], 400);
    }

    public function testProductionListIncludesCreatedOrders(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');
        $production = $this->createProduction($admin['access_token'], $variantId, '3.0000');

        $client = static::createClient();
        $client->request(
            'GET',
            '/api/productions?page=1',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
        );
        self::assertResponseIsSuccessful();
        $list = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(1, $list['meta']['total']);
        self::assertContains($production['id'], array_column($list['items'], 'id'));
    }

    public function testCreateProductionRejectsInvalidQuantity(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');

        foreach (['0', 'abc', '-2'] as $quantity) {
            $client = static::createClient();
            $client->request(
                'POST',
                '/api/productions',
                server: [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token'],
                    'CONTENT_TYPE' => 'application/json',
                ],
                content: json_encode([
                    'variant_id' => $variantId,
                    'planned_quantity' => $quantity,
                ], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testConcurrentProductionsCanRunIndependently(): void
    {
        $admin = $this->login();
        $variantA = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');
        $variantB = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');

        $productionA = $this->createProduction($admin['access_token'], $variantA, '10.0000');
        $productionB = $this->createProduction($admin['access_token'], $variantB, '5.0000');

        self::assertNotSame($productionA['id'], $productionB['id']);
        self::assertSame('DRAFT', $productionA['status']);
        self::assertSame('DRAFT', $productionB['status']);

        $this->startProduction($admin['access_token'], $productionA['id']);
        $this->startProduction($admin['access_token'], $productionB['id']);

        $detailA = $this->getProduction($admin['access_token'], $productionA['id']);
        $detailB = $this->getProduction($admin['access_token'], $productionB['id']);

        self::assertSame('IN_PROGRESS', $detailA['status']);
        self::assertSame('IN_PROGRESS', $detailB['status']);
        self::assertCount(7, $detailA['stages']);
        self::assertCount(7, $detailB['stages']);
    }

    public function testProductionCompletionPostsStockAndAllocatesBackorders(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');

        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '12.0000');
        self::assertSame('4.0000', $order['items'][0]['quantity_backordered']);

        $production = $this->createProduction($admin['access_token'], $variantId, '4.0000', plan: true);
        $this->startProduction($admin['access_token'], $production['id']);

        $detail = $this->getProduction($admin['access_token'], $production['id']);
        $inputQty = '4.0000';

        foreach ($detail['stages'] as $stage) {
            $this->startStage($admin['access_token'], $production['id'], $stage['id'], $inputQty);
            $this->completeStage($admin['access_token'], $production['id'], $stage['id'], $inputQty, '0.0000');
        }

        $completed = $this->getProduction($admin['access_token'], $production['id']);
        self::assertSame('COMPLETED', $completed['status']);

        $stock = $this->getStockForVariant($admin['access_token'], $variantId);
        self::assertSame('12.0000', $stock['physical_on_hand']);
        self::assertSame('12.0000', $stock['reserved']);

        $client = static::createClient();
        $client->request(
            'GET',
            '/api/orders/'.$order['id'],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
        );
        self::assertResponseIsSuccessful();
        $updatedOrder = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.0000', $updatedOrder['items'][0]['quantity_backordered']);
        self::assertSame('READY_TO_DELIVER', $updatedOrder['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createProduction(string $token, string $variantId, string $quantity, bool $plan = false): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/productions',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'test-production-'.uniqid('', true),
            ],
            content: json_encode([
                'variant_id' => $variantId,
                'planned_quantity' => $quantity,
                'plan' => $plan,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function startProduction(string $token, string $productionId): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/productions/'.$productionId.'/start',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProduction(string $token, string $productionId): array
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/productions/'.$productionId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postJson(string $token, string $path, array $body, int $expectedStatus): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame($expectedStatus, (string) $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    private function startStage(string $token, string $productionId, string $stageId, string $inputQuantity): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/productions/'.$productionId.'/stages/'.$stageId.'/start',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['input_quantity' => $inputQuantity], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    private function completeStage(
        string $token,
        string $productionId,
        string $stageId,
        string $accepted,
        string $loss,
    ): void {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/productions/'.$productionId.'/stages/'.$stageId.'/complete',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'accepted_output_quantity' => $accepted,
                'loss_quantity' => $loss,
                // Every lost unit needs a configured reason.
                'losses' => bccomp($loss, '0', 4) > 0 ? [['reason_code' => 'QUALITY_REJECT', 'quantity' => $loss]] : [],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function createAndConfirmOrder(string $token, string $variantId, string $quantity): array
    {
        // Resolve before creating the order client: the lookup creates its own client,
        // and response assertions always read the most recently created one.
        $customerId = $this->getPortalCustomerId($token);
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/orders',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'test-order-'.uniqid('', true),
            ],
            content: json_encode([
                'customer_id' => $customerId,
                'items' => [['variant_id' => $variantId, 'quantity' => $quantity]],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $order = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        $client->request(
            'POST',
            '/api/orders/'.$order['id'].'/confirm',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    private function findVariantIdBySku(string $token, string $sku): string
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/products?per_page=100',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $products = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        foreach ($products['items'] as $product) {
            $client->request(
                'GET',
                '/api/products/'.$product['id'].'/variants',
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
            );
            self::assertResponseIsSuccessful();
            $variants = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

            foreach ($variants['items'] as $variant) {
                if ($variant['sku'] === $sku) {
                    return $variant['id'];
                }
            }
        }

        self::fail(sprintf('Variant with SKU %s not found.', $sku));
    }

    /**
     * @return array<string, mixed>
     */
    private function getStockForVariant(string $token, string $variantId): array
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/inventory/stock?variant_id='.$variantId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        return $payload['items'][0];
    }

    private function getPortalCustomerId(string $token): string
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/customers',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        foreach ($payload['items'] as $customer) {
            if ($customer['display_name'] === 'Portal Customer') {
                return $customer['id'];
            }
        }

        self::fail('Portal customer not found.');
    }
}
