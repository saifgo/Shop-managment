<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Tests\Support\AuthenticatedApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ManualDocumentsTest extends AuthenticatedApiTestCase
{
    public function testManualInvoiceMixesFreeTextAndCatalogLinesThenIssues(): void
    {
        $token = $this->login()['access_token'];
        $customerId = $this->findCustomerId($token, 'Atlas Hotel Group');
        $variantId = $this->variantId('TAG-M');
        $this->request($token, 'POST', '/api/customers/'.$customerId.'/price-overrides', [
            'variant_id' => $variantId,
            'price_amount' => '259.0000',
            'price_currency' => 'TND',
        ], 201);

        $draft = $this->request($token, 'POST', '/api/documents', [
            'document_type' => 'INVOICE',
            'customer_id' => $customerId,
            'notes' => 'Installation included',
            'lines' => [
                ['description' => 'Installation service', 'quantity' => '2', 'unit_price' => '100', 'tax_rate' => '20', 'discount_amount' => '10'],
                // No price given: the customer's override for TAG-M applies instead of the 299.0000 base price.
                ['variant_id' => $variantId, 'quantity' => '1'],
            ],
        ], 201);

        self::assertSame('INVOICE', $draft['document_type']);
        self::assertSame('DRAFT', $draft['status']);
        self::assertFalse($draft['is_posted']);
        self::assertNull($draft['document_number']);
        self::assertSame('Installation service', $draft['lines'][0]['description']);
        self::assertSame('10.0000', $draft['lines'][0]['discount_amount']['amount']);
        self::assertSame('228.0000', $draft['lines'][0]['line_total']['amount']);
        self::assertSame('TAG-M', $draft['lines'][1]['sku']);
        self::assertSame('259.0000', $draft['lines'][1]['unit_price']['amount']);
        self::assertSame('310.8000', $draft['lines'][1]['line_total']['amount']);
        self::assertSame('459.0000', $draft['subtotal']['amount']);
        self::assertSame('89.8000', $draft['tax_total']['amount']);
        self::assertSame('10.0000', $draft['discount_total']['amount']);
        self::assertSame('538.8000', $draft['grand_total']['amount']);

        $issued = $this->request($token, 'POST', '/api/documents/'.$draft['id'].'/issue', ['due_date' => '2030-01-31']);
        self::assertSame('ISSUED', $issued['status']);
        self::assertTrue($issued['is_posted']);
        self::assertStringStartsWith('INV-', (string) $issued['document_number']);
        self::assertSame('2030-01-31', $issued['due_date']);

        $invoices = $this->request($token, 'GET', '/api/invoices');
        self::assertContains($draft['id'], array_column($invoices['items'], 'id'));
    }

    public function testEachManualTypeIsPostedWithItsOwnNumberPrefix(): void
    {
        $token = $this->login()['access_token'];
        $customerId = $this->findCustomerId($token, 'Atlas Hotel Group');
        $prefixes = [
            'QUOTE' => 'QUO-',
            'PROFORMA' => 'PRO-',
            'SALES_ORDER' => 'SO-',
            'DELIVERY_NOTE' => 'DN-',
            'GOODS_ISSUE' => 'GI-',
        ];

        foreach ($prefixes as $type => $prefix) {
            $document = $this->request($token, 'POST', '/api/documents', [
                'document_type' => $type,
                'customer_id' => $customerId,
                'issue' => true,
                'lines' => [['description' => 'Custom item', 'quantity' => '3', 'unit_price' => '15.5']],
            ], 201);

            self::assertSame($type, $document['document_type']);
            self::assertSame('POSTED', $document['status']);
            self::assertStringStartsWith($prefix, (string) $document['document_number']);
            self::assertNull($document['due_date']);
        }

        $goodsIssues = $this->request($token, 'GET', '/api/documents?type=GOODS_ISSUE');
        self::assertSame(1, $goodsIssues['meta']['total']);
        self::assertSame('GOODS_ISSUE', $goodsIssues['items'][0]['document_type']);

        $all = $this->request($token, 'GET', '/api/documents');
        self::assertSame(5, $all['meta']['total']);

        $this->request($token, 'GET', '/api/documents?type=NOT_A_TYPE', expectedStatus: 400);
    }

    public function testInvalidManualDocumentsAreRejected(): void
    {
        $token = $this->login()['access_token'];
        $customerId = $this->findCustomerId($token, 'Atlas Hotel Group');
        $line = ['description' => 'Item', 'quantity' => '1', 'unit_price' => '10'];

        $cases = [
            'credit notes need an invoice' => ['document_type' => 'CREDIT_NOTE', 'customer_id' => $customerId, 'lines' => [$line]],
            'unknown type' => ['document_type' => 'RECEIPT', 'customer_id' => $customerId, 'lines' => [$line]],
            'unknown customer' => ['document_type' => 'QUOTE', 'customer_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'lines' => [$line]],
            'free text without price' => ['document_type' => 'QUOTE', 'customer_id' => $customerId, 'lines' => [['description' => 'Item', 'quantity' => '1']]],
            'line without description' => ['document_type' => 'QUOTE', 'customer_id' => $customerId, 'lines' => [['quantity' => '1', 'unit_price' => '5']]],
            'zero quantity' => ['document_type' => 'QUOTE', 'customer_id' => $customerId, 'lines' => [[...$line, 'quantity' => '0']]],
            'negative price' => ['document_type' => 'QUOTE', 'customer_id' => $customerId, 'lines' => [[...$line, 'unit_price' => '-1']]],
            'discount above amount' => ['document_type' => 'QUOTE', 'customer_id' => $customerId, 'lines' => [[...$line, 'discount_amount' => '11']]],
            'tax above 100%' => ['document_type' => 'QUOTE', 'customer_id' => $customerId, 'lines' => [[...$line, 'tax_rate' => '120']]],
        ];

        foreach ($cases as $label => $payload) {
            $client = static::createClient();
            $client->request('POST', '/api/documents', server: $this->headers($token), content: json_encode($payload, JSON_THROW_ON_ERROR));
            self::assertSame(400, $client->getResponse()->getStatusCode(), $label.': '.$client->getResponse()->getContent());
        }

        $this->request($token, 'POST', '/api/documents', [
            'document_type' => 'QUOTE',
            'customer_id' => $customerId,
            'lines' => [],
        ], 422);
    }

    public function testDraftsCanBeCancelledButPostedDocumentsCannot(): void
    {
        $token = $this->login()['access_token'];
        $customerId = $this->findCustomerId($token, 'Atlas Hotel Group');
        $payload = [
            'document_type' => 'PROFORMA',
            'customer_id' => $customerId,
            'lines' => [['description' => 'Item', 'quantity' => '1', 'unit_price' => '10']],
        ];

        $draft = $this->request($token, 'POST', '/api/documents', $payload, 201);
        $cancelled = $this->request($token, 'POST', '/api/documents/'.$draft['id'].'/cancel');
        self::assertSame('CANCELLED', $cancelled['status']);
        $this->request($token, 'POST', '/api/documents/'.$draft['id'].'/issue', [], 400);

        $posted = $this->request($token, 'POST', '/api/documents', [...$payload, 'issue' => true], 201);
        $this->request($token, 'POST', '/api/documents/'.$posted['id'].'/cancel', expectedStatus: 400);
    }

    public function testManualDocumentRequiresDocumentsManagePermission(): void
    {
        $portalToken = $this->login('customer@tittawin.local')['access_token'];

        $this->request($portalToken, 'POST', '/api/documents', [
            'document_type' => 'QUOTE',
            'customer_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'lines' => [['description' => 'Item', 'quantity' => '1', 'unit_price' => '10']],
        ], 403);
        $this->request($portalToken, 'GET', '/api/documents', expectedStatus: 403);
    }

    public function testIdempotencyKeyReturnsTheSameDocument(): void
    {
        $token = $this->login()['access_token'];
        $payload = [
            'document_type' => 'QUOTE',
            'customer_id' => $this->findCustomerId($token, 'Atlas Hotel Group'),
            'lines' => [['description' => 'Item', 'quantity' => '1', 'unit_price' => '10']],
        ];

        $first = $this->request($token, 'POST', '/api/documents', $payload, 201, 'quote-once');
        $second = $this->request($token, 'POST', '/api/documents', $payload, 201, 'quote-once');
        self::assertSame($first['id'], $second['id']);
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

    /** @return array<string, string> */
    private function headers(string $token, ?string $idempotencyKey = null): array
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY-KEY'] = $idempotencyKey;
        }

        return $headers;
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
        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            server: $this->headers($token, $idempotencyKey),
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : ($method === 'POST' ? '{}' : null),
        );
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }
}
