<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class ReturnsPurchasingFinanceTest extends AuthenticatedApiTestCase
{
    public function testDamagedInTransitExchangeStockTraceableAndReplacementFlow(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $stockBefore = $this->getVariantStock($admin['access_token'], $variantId);

        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '1.0000');
        $orderItemId = $order['items'][0]['id'];
        $delivery = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '1.0000'],
        ]);
        $this->shipDelivery($admin['access_token'], $delivery['id']);

        $stockAfterShip = $this->getVariantStock($admin['access_token'], $variantId);
        self::assertSame(bcsub($stockBefore, '1.0000', 4), $stockAfterShip);

        $returnRequest = $this->createReturn($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '1.0000'],
        ], 'Damaged in transit');
        $this->approveReturn($admin['access_token'], $returnRequest['id']);
        $this->receiveReturn($admin['access_token'], $returnRequest['id']);
        $inspected = $this->inspectReturn($admin['access_token'], $returnRequest['id'], [
            ['return_item_id' => $returnRequest['items'][0]['id'], 'condition' => 'DAMAGED_IN_TRANSIT'],
        ]);
        self::assertSame('INSPECTED', $inspected['status']);

        $resolved = $this->resolveReturn($admin['access_token'], $returnRequest['id'], 'REPLACEMENT');
        self::assertSame('RESOLVED', $resolved['status']);
        self::assertSame('REPLACEMENT', $resolved['resolution']);
        self::assertNotNull($resolved['replacement_delivery_id']);

        $this->shipDelivery($admin['access_token'], $resolved['replacement_delivery_id']);
        $stockAfterReplacement = $this->getVariantStock($admin['access_token'], $variantId);
        self::assertSame(bcsub($stockAfterShip, '1.0000', 4), $stockAfterReplacement);

        $movements = $this->getMovements($admin['access_token'], $variantId);
        $sourceTypes = array_column($movements, 'source_type');
        self::assertContains('delivery', $sourceTypes);
        self::assertContains('replacement_delivery', $sourceTypes);
    }

    public function testReturnAcceptedWithCreditNoteReducesReceivable(): void
    {
        $admin = $this->login();
        $customerId = $this->getPortalCustomerId($admin['access_token']);
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $order = $this->createAndConfirmOrder($admin['access_token'], $variantId, '1.0000', $customerId);
        $orderItemId = $order['items'][0]['id'];

        $delivery = $this->createDelivery($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '1.0000'],
        ]);
        $this->shipDelivery($admin['access_token'], $delivery['id']);
        $invoice = $this->issueInvoice($admin['access_token'], $this->createInvoiceFromDelivery($admin['access_token'], $delivery['id'])['id']);

        $balanceBefore = $this->getCustomerBalance($admin['access_token'], $customerId);
        self::assertNotSame('0.0000', $balanceBefore['amount']);

        $returnRequest = $this->createReturn($admin['access_token'], $order['id'], [
            ['order_item_id' => $orderItemId, 'quantity' => '1.0000'],
        ]);
        $this->approveReturn($admin['access_token'], $returnRequest['id']);
        $this->receiveReturn($admin['access_token'], $returnRequest['id']);
        $this->inspectReturn($admin['access_token'], $returnRequest['id'], [
            ['return_item_id' => $returnRequest['items'][0]['id'], 'condition' => 'SELLABLE'],
        ]);
        $resolved = $this->resolveReturn($admin['access_token'], $returnRequest['id'], 'CREDIT_NOTE', $invoice['id']);
        self::assertNotNull($resolved['credit_note_id']);

        $balanceAfter = $this->getCustomerBalance($admin['access_token'], $customerId);
        self::assertSame('0.0000', $balanceAfter['amount']);
    }

    public function testPurchaseReceiptIncreasesStock(): void
    {
        $admin = $this->login();
        $variantId = $this->findVariantIdBySku($admin['access_token'], 'BWL-4');
        $stockBefore = $this->getVariantStock($admin['access_token'], $variantId);

        $supplier = $this->createSupplier($admin['access_token']);
        $po = $this->createPurchaseOrder($admin['access_token'], $supplier['id'], $variantId, '5.0000');
        $poItemId = $po['items'][0]['id'];

        $receipt = $this->receivePurchaseOrder($admin['access_token'], $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'quantity' => '5.0000'],
        ]);
        self::assertSame('RCV-', substr($receipt['reference'], 0, 4));

        $stockAfter = $this->getVariantStock($admin['access_token'], $variantId);
        self::assertSame(bcadd($stockBefore, '5.0000', 4), $stockAfter);
    }

    public function testSupplierPaymentAllocation(): void
    {
        $admin = $this->login();
        $supplier = $this->createSupplier($admin['access_token']);
        $invoice = $this->createSupplierInvoice($admin['access_token'], $supplier['id'], '500.0000');
        $payment = $this->recordSupplierPayment($admin['access_token'], $supplier['id'], '500.0000');
        self::assertSame('RECORDED', $payment['status']);

        $allocated = $this->allocateSupplierPayment($admin['access_token'], $payment['id'], $invoice['id'], '500.0000');
        self::assertSame('FULLY_ALLOCATED', $allocated['status']);

        $balance = $this->getSupplierBalance($admin['access_token'], $supplier['id']);
        self::assertSame('0.0000', $balance['amount']);
    }

    /** @param list<array{order_item_id: string, quantity: string}> $items */
    /** @return array<string, mixed> */
    private function createReturn(string $token, string $orderId, array $items, ?string $reason = null): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/returns',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'return-' . uniqid('', true),
            ],
            content: json_encode([
                'order_id' => $orderId,
                'reason' => $reason,
                'items' => $items,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function approveReturn(string $token, string $returnId): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/returns/' . $returnId . '/approve',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function receiveReturn(string $token, string $returnId): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/returns/' . $returnId . '/receive',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array{return_item_id: string, condition: string}> $items */
    /** @return array<string, mixed> */
    private function inspectReturn(string $token, string $returnId, array $items): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/returns/' . $returnId . '/inspect',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['items' => $items], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function resolveReturn(string $token, string $returnId, string $resolution, ?string $invoiceId = null): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/returns/' . $returnId . '/resolve',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'resolve-' . uniqid('', true),
            ],
            content: json_encode([
                'resolution' => $resolution,
                'invoice_id' => $invoiceId,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function createSupplier(string $token): array
    {
        $client = static::createClient();
        $code = 'SUP-' . substr(uniqid('', true), -6);
        $client->request(
            'POST',
            '/api/suppliers',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => $code, 'name' => 'Test Supplier ' . $code], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function createPurchaseOrder(string $token, string $supplierId, string $variantId, string $quantity): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/purchase-orders',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'po-' . uniqid('', true),
            ],
            content: json_encode([
                'supplier_id' => $supplierId,
                'currency' => 'TND',
                'items' => [['variant_id' => $variantId, 'quantity' => $quantity, 'unit_price' => '10.0000']],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array{purchase_order_item_id: string, quantity: string}> $lines */
    /** @return array<string, mixed> */
    private function receivePurchaseOrder(string $token, string $poId, array $lines): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/purchase-orders/' . $poId . '/receive',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'rcv-' . uniqid('', true),
            ],
            content: json_encode(['lines' => $lines], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function createSupplierInvoice(string $token, string $supplierId, string $amount): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/supplier-invoices',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'supplier_id' => $supplierId,
                'invoice_number' => 'SINV-' . uniqid('', true),
                'total_amount' => $amount,
                'currency' => 'TND',
                'issued_at' => date('Y-m-d'),
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function recordSupplierPayment(string $token, string $supplierId, string $amount): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/supplier-payments',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY-KEY' => 'spay-' . uniqid('', true),
            ],
            content: json_encode([
                'supplier_id' => $supplierId,
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
    private function allocateSupplierPayment(string $token, string $paymentId, string $invoiceId, string $amount): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/supplier-payments/' . $paymentId . '/allocate',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function getSupplierBalance(string $token, string $supplierId): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/suppliers/' . $supplierId . '/balance', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }

    private function getVariantStock(string $token, string $variantId): string
    {
        $client = static::createClient();
        $client->request('GET', '/api/inventory/stock?variant_id=' . $variantId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['items'] as $item) {
            if ($item['variant_id'] === $variantId) {
                return $item['physical_on_hand'];
            }
        }

        return '0.0000';
    }

    /** @return list<array<string, mixed>> */
    private function getMovements(string $token, string $variantId): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/inventory/movements?variant_id=' . $variantId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        return $data['items'] ?? [];
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

        $client->request('POST', '/api/orders/' . $order['id'] . '/confirm', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
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

    private function shipDelivery(string $token, string $deliveryId): void
    {
        $client = static::createClient();
        foreach (['PACKED', 'DISPATCHED', 'DELIVERED'] as $status) {
            $client->request(
                'POST',
                '/api/deliveries/' . $deliveryId . '/transition',
                server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                content: json_encode(['status' => $status], JSON_THROW_ON_ERROR),
            );
            self::assertResponseIsSuccessful();
        }
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
