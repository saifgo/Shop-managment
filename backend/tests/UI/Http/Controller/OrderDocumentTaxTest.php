<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Tests\Support\AuthenticatedApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class OrderDocumentTaxTest extends AuthenticatedApiTestCase
{
    public function testInvoiceFromOrderHasTheSameTotalsAsTheOrder(): void
    {
        $token = $this->login()['access_token'];
        $order = $this->createOrder($token, 'VAS-M', '3');

        // 3 × 189.0000 at the default 20%.
        self::assertSame('20.0000', $order['items'][0]['tax_rate']);
        self::assertSame('113.4000', $order['tax_total']['amount']);
        self::assertSame('680.4000', $order['grand_total']['amount']);

        $invoice = $this->request($token, 'POST', '/api/invoices', ['order_id' => $order['id']], 201, 'invoice-'.$order['id']);

        self::assertSame('20.0000', $invoice['lines'][0]['tax_rate']);
        self::assertSame($order['subtotal']['amount'], $invoice['subtotal']['amount']);
        self::assertSame($order['tax_total']['amount'], $invoice['tax_total']['amount']);
        self::assertSame($order['grand_total']['amount'], $invoice['grand_total']['amount']);

        $salesOrderDocument = $this->request($token, 'POST', '/api/orders/'.$order['id'].'/documents/sales-order', expectedStatus: 201);
        self::assertSame($order['grand_total']['amount'], $salesOrderDocument['grand_total']['amount']);
    }

    public function testChangedTaxRateAppliesToNewOrdersAndDocumentsOnly(): void
    {
        $token = $this->login()['access_token'];
        self::assertSame('20.0000', $this->request($token, 'GET', '/api/settings/tax')['default_tax_rate']);

        $before = $this->createOrder($token, 'VAS-M', '3');

        $updated = $this->request($token, 'PUT', '/api/settings/tax', ['default_tax_rate' => '7.5']);
        self::assertSame('7.5000', $updated['default_tax_rate']);
        self::assertNotNull($updated['updated_at']);
        self::assertSame('7.5000', $this->request($token, 'GET', '/api/settings/tax')['default_tax_rate']);

        $after = $this->createOrder($token, 'VAS-M', '3');
        self::assertSame('7.5000', $after['items'][0]['tax_rate']);
        self::assertSame('42.5250', $after['tax_total']['amount']);
        self::assertSame('609.5250', $after['grand_total']['amount']);

        // The earlier order keeps the rate it was priced with, and so do documents built from it.
        $oldInvoice = $this->request($token, 'POST', '/api/invoices', ['order_id' => $before['id']], 201, 'invoice-'.$before['id']);
        self::assertSame('20.0000', $oldInvoice['lines'][0]['tax_rate']);
        self::assertSame($before['grand_total']['amount'], $oldInvoice['grand_total']['amount']);

        $newInvoice = $this->request($token, 'POST', '/api/invoices', ['order_id' => $after['id']], 201, 'invoice-'.$after['id']);
        self::assertSame($after['grand_total']['amount'], $newInvoice['grand_total']['amount']);

        // Manual lines without an explicit tax_rate default to the configured rate.
        $manual = $this->request($token, 'POST', '/api/documents', [
            'document_type' => 'QUOTE',
            'customer_id' => $this->findCustomerId($token, 'Atlas Hotel Group'),
            'lines' => [['description' => 'Item', 'quantity' => '1', 'unit_price' => '100']],
        ], 201);
        self::assertSame('7.5000', $manual['lines'][0]['tax_rate']);
        self::assertSame('107.5000', $manual['grand_total']['amount']);
    }

    public function testTaxRateUpdateIsValidatedAndRequiresSettingsPermission(): void
    {
        $adminToken = $this->login()['access_token'];

        foreach (['120', '-5', 'abc', '1.23456'] as $invalid) {
            $this->request($adminToken, 'PUT', '/api/settings/tax', ['default_tax_rate' => $invalid], 400);
        }

        $salesToken = $this->login('sales@tittawin.local')['access_token'];
        self::assertSame('20.0000', $this->request($salesToken, 'GET', '/api/settings/tax')['default_tax_rate']);
        $this->request($salesToken, 'PUT', '/api/settings/tax', ['default_tax_rate' => '10'], 403);

        self::assertSame('20.0000', $this->request($adminToken, 'GET', '/api/settings/tax')['default_tax_rate']);
    }

    /** @return array<string, mixed> */
    private function createOrder(string $token, string $sku, string $quantity): array
    {
        return $this->request($token, 'POST', '/api/orders', [
            'customer_id' => $this->findCustomerId($token, 'Atlas Hotel Group'),
            'items' => [['variant_id' => $this->variantId($sku), 'quantity' => $quantity]],
        ], 201, 'order-'.uniqid('', true));
    }

    private function variantId(string $sku): string
    {
        $variant = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ProductVariant::class)
            ->findOneBy(['sku' => $sku]);
        self::assertInstanceOf(ProductVariant::class, $variant);

        return $variant->getId();
    }

    private function findCustomerId(string $token, string $displayName): string
    {
        $customers = $this->request($token, 'GET', '/api/customers?per_page=100');

        foreach ($customers['items'] as $customer) {
            if ($customer['display_name'] === $displayName) {
                return $customer['id'];
            }
        }

        self::fail(sprintf('Customer "%s" not seeded.', $displayName));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(
        string $token,
        string $method,
        string $uri,
        ?array $body = null,
        int $expectedStatus = 200,
        ?string $idempotencyKey = null,
    ): array {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY-KEY'] = $idempotencyKey;
        }

        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            server: $headers,
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : ($method === 'POST' ? '{}' : null),
        );
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }
}
