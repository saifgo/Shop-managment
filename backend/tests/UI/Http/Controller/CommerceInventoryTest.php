<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class CommerceInventoryTest extends AuthenticatedApiTestCase
{
    public function testInStockOrderReservesStock(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');

        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '2.0000');
        self::assertSame('READY_TO_DELIVER', $order['status']);

        $item = $order['items'][0];
        self::assertSame('2.0000', $item['quantity_reserved']);
        self::assertSame('0.0000', $item['quantity_backordered']);
        self::assertSame('READY', $item['line_status']);

        $stock = $this->getStockForVariant($admin['access_token'], $variantId);
        self::assertSame('20.0000', $stock['physical_on_hand']);
        self::assertSame('2.0000', $stock['reserved']);
        self::assertSame('18.0000', $stock['available_to_sell']);
    }

    public function testOverStockOrderCreatesPartialReservationAndBackorder(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');

        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '12.0000');
        self::assertSame('PARTIALLY_ALLOCATED', $order['status']);

        $item = $order['items'][0];
        self::assertSame('8.0000', $item['quantity_reserved']);
        self::assertSame('4.0000', $item['quantity_backordered']);
        self::assertSame('BACKORDERED', $item['line_status']);

        $stock = $this->getStockForVariant($admin['access_token'], $variantId);
        self::assertSame('8.0000', $stock['physical_on_hand']);
        self::assertSame('8.0000', $stock['reserved']);
        self::assertSame('0.0000', $stock['available_to_sell']);
        self::assertSame('4.0000', $stock['confirmed_demand']);
    }

    public function testDemandViewsReturnCorrectProjections(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'TAG-M');
        $this->createAndConfirmOrder($admin['access_token'], $variantId, '12.0000');

        $client = static::createClient();
        $client->request(
            'GET',
            '/api/demand/by-product?variant_id='.$variantId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
        );
        self::assertResponseIsSuccessful();
        $byProduct = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $byProduct['items']);
        self::assertSame('12.0000', $byProduct['items'][0]['ordered']);
        self::assertSame('4.0000', $byProduct['items'][0]['backordered']);

        $client->request(
            'GET',
            '/api/demand/by-customer',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
        );
        self::assertResponseIsSuccessful();
        $byCustomer = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($byCustomer['items']);
        self::assertSame('4.0000', $byCustomer['items'][0]['backordered']);
    }

    public function testStockMovementsAreImmutableAndCorrectedViaCompensatingEntry(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'VAS-M');
        $locationId = $this->getDefaultLocationId($admin['access_token']);

        $client = static::createClient();
        $client->request(
            'POST',
            '/api/inventory/adjustments',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'quantity_delta' => '-1.0000',
                'reason' => 'Count correction',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $adjustment = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        $originalMovementId = $adjustment['movement']['id'];

        $client->request(
            'GET',
            '/api/inventory/movements?variant_id='.$variantId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
        );
        self::assertResponseIsSuccessful();
        $movements = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(2, $movements['meta']['total']);

        $client->request(
            'POST',
            '/api/inventory/adjustments',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'quantity_delta' => '1.0000',
                'reason' => 'Compensating correction for '.$originalMovementId,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        $stock = $this->getStockForVariant($admin['access_token'], $variantId);
        self::assertSame('3.0000', $stock['physical_on_hand']);
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

    private function getDefaultLocationId(string $token): string
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/inventory/stock',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        return $payload['items'][0]['location_id'];
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
