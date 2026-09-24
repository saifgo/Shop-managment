<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Tests\Support\AuthenticatedApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PurchasingTest extends AuthenticatedApiTestCase
{
    public function testSupplierListReportsTotalAcrossPages(): void
    {
        $token = $this->login()['access_token'];
        $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-A', 'name' => 'Argile Atlas'], 201);
        $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-B', 'name' => 'Bois du Sud'], 201);

        $page = $this->request($token, 'GET', '/api/suppliers?per_page=1');
        self::assertCount(1, $page['items']);
        self::assertSame(2, $page['meta']['total']);
        self::assertSame(2, $page['meta']['total_pages']);
    }

    public function testDuplicateSupplierCodeIsAConflict(): void
    {
        $token = $this->login()['access_token'];
        $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-A', 'name' => 'Argile Atlas'], 201);
        $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-A', 'name' => 'Someone else'], 409);
    }

    public function testPurchaseOrderLinesAreValidatedAndDescribed(): void
    {
        $token = $this->login()['access_token'];
        $supplier = $this->request($token, 'POST', '/api/suppliers', ['code' => 'SUP-A', 'name' => 'Argile Atlas'], 201);
        $variantId = $this->variantId('TAG-M');

        foreach (['0', '-2', 'abc'] as $quantity) {
            $this->request($token, 'POST', '/api/purchase-orders', [
                'supplier_id' => $supplier['id'],
                'currency' => 'TND',
                'items' => [['variant_id' => $variantId, 'quantity' => $quantity, 'unit_price' => '120']],
            ], 400);
        }

        $po = $this->request($token, 'POST', '/api/purchase-orders', [
            'supplier_id' => $supplier['id'],
            'currency' => 'TND',
            'expected_at' => '2030-02-01',
            'items' => [['variant_id' => $variantId, 'quantity' => '4', 'unit_price' => '120']],
        ], 201);

        self::assertSame('SENT', $po['status']);
        self::assertSame('Medium', $po['items'][0]['variant_name']);
        self::assertNotEmpty($po['items'][0]['product_name']);
        self::assertSame('480.0000', $po['grand_total']['amount']);

        $list = $this->request($token, 'GET', '/api/purchase-orders');
        self::assertSame(1, $list['meta']['total']);
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
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }
}
