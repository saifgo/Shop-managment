<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class ProductControllerTest extends AuthenticatedApiTestCase
{
    public function testListProductsReturnsSeededCatalog(): void
    {
        $login = $this->login();
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/products',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertGreaterThanOrEqual(3, $data['meta']['total']);
        $names = array_column($data['items'], 'name');
        self::assertContains('Berber Tagine', $names);
    }

    public function testPortalUserSeesPublicProductsWithOverridePricing(): void
    {
        $login = $this->login('customer@tittawin.local');
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/products?search=tagine',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data['items']);

        $productId = $data['items'][0]['id'];
        $client->request(
            'GET',
            '/api/products/'.$productId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        $detail = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        $mediumVariant = null;

        foreach ($detail['variants'] as $variant) {
            if ($variant['sku'] === 'TAG-M') {
                $mediumVariant = $variant;
                break;
            }
        }

        self::assertNotNull($mediumVariant);
        self::assertSame('279.0000', $mediumVariant['price']['amount']);
        self::assertSame('customer_override', $mediumVariant['price']['source']);
    }

    public function testCreateProductRequiresManagePermission(): void
    {
        $login = $this->login('customer@tittawin.local');
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/products',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token'], 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => 'Forbidden Product',
                'slug' => 'forbidden-product',
                'visibility' => 'public',
                'backorder_policy' => 'allow',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }
}
