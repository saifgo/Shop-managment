<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class CatalogAndFulfillmentWorkflowTest extends AuthenticatedApiTestCase
{
    public function testAdminCanEditVariantPriceAndDeactivateIt(): void
    {
        $admin = $this->login()['access_token'];
        [$productId, $variant] = $this->findVariant($admin, 'BWL-4');

        $updated = $this->request($admin, 'PATCH', '/api/products/'.$productId.'/variants/'.$variant['id'], [
            'sku' => 'BWL-4',
            'name' => $variant['name'],
            'base_price_amount' => '99.5',
            'base_price_currency' => 'tnd',
            'attributes' => ['size' => 'large'],
            'is_active' => false,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('99.5000', $updated['base_price']['amount']);
        self::assertSame('TND', $updated['base_price']['currency']);
        self::assertSame(['size' => 'large'], $updated['attributes']);
        self::assertFalse($updated['is_active']);

        // Customers no longer see the deactivated variant.
        $customer = $this->login('customer@tittawin.local')['access_token'];
        $detail = $this->request($customer, 'GET', '/api/products/'.$productId);
        self::assertNotContains('BWL-4', array_column(array_filter($detail['variants'], static fn (array $v) => $v['is_active']), 'sku'));
    }

    public function testDuplicateSkuIsRejectedWithAReadableConflict(): void
    {
        $admin = $this->login()['access_token'];
        [$productId, $variant] = $this->findVariant($admin, 'BWL-4');

        $error = $this->request($admin, 'PATCH', '/api/products/'.$productId.'/variants/'.$variant['id'], [
            'sku' => 'TAG-M',
            'name' => $variant['name'],
            'base_price_amount' => '10',
            'base_price_currency' => 'TND',
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('TAG-M', $error['error']['message']);

        $this->request($admin, 'POST', '/api/products/'.$productId.'/variants', [
            'sku' => 'TAG-M',
            'name' => 'Copy',
            'base_price_amount' => '10',
            'base_price_currency' => 'TND',
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCatalogExposesStockAndFindsProductsBySku(): void
    {
        $admin = $this->login()['access_token'];
        $list = $this->request($admin, 'GET', '/api/products?search=bwl-4');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $list['items']);
        self::assertContains($list['items'][0]['stock_status'], ['in_stock', 'low_stock', 'out_of_stock']);
        self::assertArrayHasKey('available_quantity', $list['items'][0]);

        $detail = $this->request($admin, 'GET', '/api/products/'.$list['items'][0]['id']);
        self::assertArrayHasKey('on_hand', $detail['variants'][0]);
        self::assertArrayHasKey('available_quantity', $detail['variants'][0]);

        // Customers see availability but not internal on-hand figures.
        $customer = $this->login('customer@tittawin.local')['access_token'];
        $portalDetail = $this->request($customer, 'GET', '/api/products/'.$list['items'][0]['id']);
        self::assertArrayNotHasKey('on_hand', $portalDetail['variants'][0]);
        self::assertArrayHasKey('stock_status', $portalDetail['variants'][0]);

        $inactive = $this->request($admin, 'GET', '/api/products?status=inactive');
        self::assertSame(0, $inactive['meta']['total']);
    }

    public function testOpenDeliveriesCannotShipMoreThanWasOrdered(): void
    {
        $admin = $this->login()['access_token'];
        [, $variant] = $this->findVariant($admin, 'BWL-4');
        $order = $this->createConfirmedOrder($admin, $variant['id'], '4.0000');
        $itemId = $order['items'][0]['id'];
        self::assertTrue($order['can_create_delivery']);
        self::assertSame('4.0000', $order['items'][0]['quantity_deliverable']);

        $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $itemId, 'quantity' => '3.0000']],
        ]);
        self::assertResponseStatusCodeSame(201);

        $refreshed = $this->request($admin, 'GET', '/api/orders/'.$order['id']);
        self::assertSame('3.0000', $refreshed['items'][0]['quantity_in_open_deliveries']);
        self::assertSame('1.0000', $refreshed['items'][0]['quantity_deliverable']);

        $error = $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $itemId, 'quantity' => '2.0000']],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('only 1.0000 remaining', $error['error']['message']);

        $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $itemId, 'quantity' => '0']],
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $itemId, 'quantity' => '1.0000']],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->request($admin, 'GET', '/api/orders/'.$order['id'])['can_create_delivery']);
    }

    public function testADeliveryCanOnlyBeInvoicedOnce(): void
    {
        $admin = $this->login()['access_token'];
        [, $variant] = $this->findVariant($admin, 'BWL-4');
        $order = $this->createConfirmedOrder($admin, $variant['id'], '1.0000');
        $delivery = $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => '1.0000']],
        ]);

        $this->request($admin, 'POST', '/api/invoices', ['delivery_id' => $delivery['id']]);
        self::assertResponseStatusCodeSame(201);

        $deliveries = $this->request($admin, 'GET', '/api/deliveries?order_id='.$order['id']);
        self::assertNotNull($deliveries['items'][0]['invoice']);
        self::assertCount(1, $deliveries['items'][0]['lines']);

        $error = $this->request($admin, 'POST', '/api/invoices', ['delivery_id' => $delivery['id']]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already invoiced', $error['error']['message']);
    }

    public function testInvalidTransitionsExplainWhyInsteadOfFailingWithAServerError(): void
    {
        $admin = $this->login()['access_token'];
        [, $variant] = $this->findVariant($admin, 'BWL-4');
        $order = $this->createConfirmedOrder($admin, $variant['id'], '1.0000');
        $delivery = $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $order['items'][0]['id'], 'quantity' => '1.0000']],
        ]);

        $error = $this->request($admin, 'POST', '/api/deliveries/'.$delivery['id'].'/transition', ['status' => 'DELIVERED']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('BUSINESS_RULE_VIOLATION', $error['error']['code']);
        self::assertStringContainsString('READY_TO_DELIVER to DELIVERED', $error['error']['message']);
    }

    public function testReturnsAreLimitedToDeliveredUnitsNotAlreadyReturned(): void
    {
        $admin = $this->login()['access_token'];
        [, $variant] = $this->findVariant($admin, 'BWL-4');
        $order = $this->createConfirmedOrder($admin, $variant['id'], '3.0000');
        $itemId = $order['items'][0]['id'];
        self::assertSame('0.0000', $order['items'][0]['quantity_returnable']);

        $delivery = $this->request($admin, 'POST', '/api/orders/'.$order['id'].'/create-delivery', [
            'lines' => [['order_item_id' => $itemId, 'quantity' => '2.0000']],
        ]);
        foreach (['PACKED', 'DISPATCHED', 'DELIVERED'] as $status) {
            $this->request($admin, 'POST', '/api/deliveries/'.$delivery['id'].'/transition', ['status' => $status]);
        }
        self::assertSame('2.0000', $this->request($admin, 'GET', '/api/orders/'.$order['id'])['items'][0]['quantity_returnable']);

        $error = $this->request($admin, 'POST', '/api/returns', [
            'order_id' => $order['id'],
            'reason' => 'Chipped',
            'items' => [['order_item_id' => $itemId, 'quantity' => '3.0000']],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Only 2.0000', $error['error']['message']);

        $this->request($admin, 'POST', '/api/returns', [
            'order_id' => $order['id'],
            'reason' => 'Chipped',
            'items' => [['order_item_id' => $itemId, 'quantity' => '1.0000']],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('1.0000', $this->request($admin, 'GET', '/api/orders/'.$order['id'])['items'][0]['quantity_returnable']);

        $this->request($admin, 'POST', '/api/returns', [
            'order_id' => $order['id'],
            'items' => [['order_item_id' => $itemId, 'quantity' => '0']],
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testOrdersCanBeSearchedAndFilteredByStatus(): void
    {
        $admin = $this->login()['access_token'];
        [, $variant] = $this->findVariant($admin, 'BWL-4');
        $order = $this->createConfirmedOrder($admin, $variant['id'], '1.0000');

        $byReference = $this->request($admin, 'GET', '/api/orders?search='.strtolower($order['reference']));
        self::assertSame([$order['id']], array_column($byReference['items'], 'id'));

        $byCustomer = $this->request($admin, 'GET', '/api/orders?search='.urlencode(substr($order['customer_name'], 0, 4)));
        self::assertContains($order['id'], array_column($byCustomer['items'], 'id'));

        $byStatus = $this->request($admin, 'GET', '/api/orders?status=SUBMITTED,'.$order['status']);
        self::assertContains($order['id'], array_column($byStatus['items'], 'id'));

        $none = $this->request($admin, 'GET', '/api/orders?status=CANCELLED');
        self::assertNotContains($order['id'], array_column($none['items'], 'id'));

        $dashboard = $this->request($admin, 'GET', '/api/dashboard/admin');
        self::assertSame($order['id'], $dashboard['recent_orders'][0]['id']);
        self::assertArrayHasKey('orders_to_confirm', $dashboard);
        self::assertArrayHasKey('low_stock_items', $dashboard);
        self::assertSame(1, $dashboard['sales_this_month']['order_count']);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function findVariant(string $token, string $sku): array
    {
        $list = $this->request($token, 'GET', '/api/products?per_page=100');

        foreach ($list['items'] as $product) {
            $detail = $this->request($token, 'GET', '/api/products/'.$product['id']);

            foreach ($detail['variants'] as $variant) {
                if ($variant['sku'] === $sku) {
                    return [$product['id'], $variant];
                }
            }
        }

        self::fail('Variant '.$sku.' not found.');
    }

    /** @return array<string, mixed> */
    private function createConfirmedOrder(string $token, string $variantId, string $quantity): array
    {
        $customers = $this->request($token, 'GET', '/api/customers?per_page=100');
        $order = $this->request($token, 'POST', '/api/orders', [
            'customer_id' => $customers['items'][0]['id'],
            'items' => [['variant_id' => $variantId, 'quantity' => $quantity]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->request($token, 'POST', '/api/orders/'.$order['id'].'/confirm');
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $token, string $method, string $uri, ?array $body = null): array
    {
        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        );

        return json_decode($client->getResponse()->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    }
}
