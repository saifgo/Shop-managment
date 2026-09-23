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
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function createAndConfirmOrder(string $token, string $variantId, string $quantity): array
    {
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
                'customer_id' => $this->getPortalCustomerId($token),
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
            '/api/products?search='.urlencode(substr($sku, 0, 3)),
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
