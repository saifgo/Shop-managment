<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class FulfillmentFinanceTest extends AuthenticatedApiTestCase
{
    public function testPartialDeliveryScenario(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '4.0000');
        $orderItemId = $order['items'][0]['id'];

        $delivery = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '2.0000'],
        ]);
        self::assertSame('READY_TO_DELIVER', $delivery['status']);

        $this->transitionDelivery($admin['access_token'], $delivery['id'], 'PACKED');
        $this->transitionDelivery($admin['access_token'], $delivery['id'], 'DISPATCHED');
        $delivered = $this->transitionDelivery($admin['access_token'], $delivery['id'], 'DELIVERED');
        self::assertSame('DELIVERED', $delivered['status']);

        $updatedOrder = $this->getOrder($admin['access_token'], $order['id']);
        self::assertSame('PARTIALLY_DELIVERED', $updatedOrder['status']);
        self::assertSame('2.0000', $updatedOrder['items'][0]['quantity_delivered']);
    }

    public function testMultiShipmentFromOneOrder(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '4.0000');
        $orderItemId = $order['items'][0]['id'];

        $first = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '1.0000'],
        ]);
        $second = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '3.0000'],
        ]);

        self::assertNotSame($first['id'], $second['id']);

        foreach ([$first, $second] as $delivery) {
            $this->transitionDelivery($admin['access_token'], $delivery['id'], 'PACKED');
            $this->transitionDelivery($admin['access_token'], $delivery['id'], 'DISPATCHED');
            $this->transitionDelivery($admin['access_token'], $delivery['id'], 'DELIVERED');
        }

        $updatedOrder = $this->getOrder($admin['access_token'], $order['id']);
        self::assertSame('DELIVERED', $updatedOrder['status']);
        self::assertSame('4.0000', $updatedOrder['items'][0]['quantity_delivered']);
    }

    public function testInvoicePartialThenFullPayment(): void
    {
        $admin = $this->login();
        $customerId = $this->getPortalCustomerId($admin['access_token']);
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '2.0000', $customerId);
        $orderItemId = $order['items'][0]['id'];

        $delivery = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '2.0000'],
        ]);
        $this->shipDelivery($admin['access_token'], $delivery['id']);

        $invoice = $this->createInvoiceFromDelivery($admin['access_token'], $delivery['id']);
        $issued = $this->issueInvoice($admin['access_token'], $invoice['id']);
        self::assertSame('ISSUED', $issued['status']);
        self::assertTrue($issued['is_posted']);

        $total = $issued['grand_total']['amount'];
        $partial = bcdiv($total, '2', 4);

        $payment = $this->recordPayment($admin['access_token'], $customerId, $total);
        $allocated = $this->allocatePayment($admin['access_token'], $payment['id'], $issued['id'], $partial);
        self::assertSame('PARTIALLY_ALLOCATED', $allocated['status']);

        $invoiceAfterPartial = $this->getInvoice($admin['access_token'], $issued['id']);
        self::assertSame('PARTIALLY_PAID', $invoiceAfterPartial['status']);

        $this->allocatePayment($admin['access_token'], $payment['id'], $issued['id'], bcsub($total, $partial, 4));
        $invoicePaid = $this->getInvoice($admin['access_token'], $issued['id']);
        self::assertSame('PAID', $invoicePaid['status']);

        $balance = $this->getCustomerBalance($admin['access_token'], $customerId);
        self::assertSame('0.0000', $balance['amount']);
    }

    public function testPostedInvoiceImmutabilityRequiresCreditNote(): void
    {
        $admin = $this->login();
        $customerId = $this->getPortalCustomerId($admin['access_token']);
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '1.0000', $customerId);
        $delivery = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $order['items'][0]['id'], 'quantity' => '1.0000'],
        ]);
        $this->shipDelivery($admin['access_token'], $delivery['id']);
        $invoice = $this->issueInvoice($admin['access_token'], $this->createInvoiceFromDelivery($admin['access_token'], $delivery['id'])['id']);

        $client = static::createClient();
        $client->request(
            'POST',
            '/api/invoices/' . $invoice['id'] . '/cancel',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $admin['access_token'], 'CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(400);

        $client->request(
            'POST',
            '/api/invoices/' . $invoice['id'] . '/credit-note',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $admin['access_token'],
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'credit-' . uniqid('', true),
            ],
            content: json_encode(['reason' => 'Correction'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $creditNote = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CREDIT_NOTE', $creditNote['document_type']);
        self::assertTrue($creditNote['is_posted']);

        $creditedInvoice = $this->getInvoice($admin['access_token'], $invoice['id']);
        self::assertSame('CREDITED', $creditedInvoice['status']);
    }

    /** @return array<string, mixed> */
    private function createAndConfirmOrder(string $token, string $variantId, string $quantity, ?string $customerId = null): array
    {
        // Resolve before creating the order client: the lookup creates its own client,
        // and response assertions always read the most recently created one.
        $customerId ??= $this->getPortalCustomerId($token);
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/orders',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'order-' . uniqid('', true),
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
            '/api/orders/' . $order['id'] . '/confirm',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array{order_item_id: string, quantity: string}> $lines */
    /** @return array<string, mixed> */
    private function createDelivery(string $token, string $orderId, array $lines): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/orders/' . $orderId . '/create-delivery',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'delivery-' . uniqid('', true),
            ],
            content: json_encode(['lines' => $lines], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function transitionDelivery(string $token, string $deliveryId, string $status): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/deliveries/' . $deliveryId . '/transition',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['status' => $status], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    private function shipDelivery(string $token, string $deliveryId): void
    {
        $this->transitionDelivery($token, $deliveryId, 'PACKED');
        $this->transitionDelivery($token, $deliveryId, 'DISPATCHED');
        $this->transitionDelivery($token, $deliveryId, 'DELIVERED');
    }

    /** @return array<string, mixed> */
    private function createInvoiceFromDelivery(string $token, string $deliveryId): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/invoices',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'invoice-' . uniqid('', true),
            ],
            content: json_encode(['delivery_id' => $deliveryId], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function issueInvoice(string $token, string $invoiceId): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/invoices/' . $invoiceId . '/issue',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function getInvoice(string $token, string $invoiceId): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/invoices/' . $invoiceId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function getOrder(string $token, string $orderId): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/orders/' . $orderId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function recordPayment(string $token, string $customerId, string $amount): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/payments',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'payment-' . uniqid('', true),
            ],
            content: json_encode([
                'customer_id' => $customerId,
                'amount' => $amount,
                'currency' => 'TND',
                'method' => 'BANK_TRANSFER',
                'payment_date' => date('Y-m-d'),
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function allocatePayment(string $token, string $paymentId, string $invoiceId, string $amount): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/payments/' . $paymentId . '/allocate',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function getCustomerBalance(string $token, string $customerId): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/customers/' . $customerId . '/balance', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    private function findVariantIdBySku(string $token, string $sku): string
    {
        $client = static::createClient();
        $client->request('GET', '/api/products?per_page=100', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $products = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        foreach ($products['items'] as $product) {
            $client->request('GET', '/api/products/' . $product['id'] . '/variants', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseIsSuccessful();
            $variants = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

            foreach ($variants['items'] as $variant) {
                if ($variant['sku'] === $sku) {
                    return $variant['id'];
                }
            }
        }

        self::fail('Variant not found for SKU ' . $sku);
    }

    private function getPortalCustomerId(string $token): string
    {
        $client = static::createClient();
        $client->request('GET', '/api/customers?per_page=100', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $customers = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        foreach ($customers['items'] as $customer) {
            if ($customer['portal_user_id'] !== null) {
                return $customer['id'];
            }
        }

        self::fail('Portal customer not found.');
    }
}
