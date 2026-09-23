<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class CustomerControllerTest extends AuthenticatedApiTestCase
{
    public function testListCustomersIncludesSeededRecords(): void
    {
        $login = $this->login();
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/customers',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(2, $data['meta']['total']);
        $names = array_column($data['items'], 'display_name');
        self::assertContains('Portal Customer', $names);
        self::assertContains('Atlas Hotel Group', $names);
    }

    public function testCreateAndGetCustomer(): void
    {
        $login = $this->login('sales@tittawin.local');
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/customers',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token'], 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'type' => 'person',
                'display_name' => 'Test Buyer',
                'legal_name' => 'Test Buyer',
                'contacts' => [
                    ['name' => 'Test Buyer', 'email' => 'buyer@example.com', 'is_primary' => true],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $created = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        $client->request(
            'GET',
            '/api/customers/'.$created['id'],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        self::assertResponseIsSuccessful();
        $fetched = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Test Buyer', $fetched['display_name']);
        self::assertCount(1, $fetched['contacts']);
    }

    public function testPortalProfileEndpoint(): void
    {
        $login = $this->login('customer@tittawin.local');
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/customers/me',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        self::assertResponseIsSuccessful();
        $profile = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Portal Customer', $profile['display_name']);
        self::assertNotEmpty($profile['addresses']);
    }

    public function testCustomerBalanceStub(): void
    {
        $login = $this->login();
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/customers',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );
        $list = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        $customerId = $list['items'][0]['id'];

        $client->request(
            'GET',
            '/api/customers/'.$customerId.'/balance',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        self::assertResponseIsSuccessful();
        $balance = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.0000', $balance['amount']);
    }
}
