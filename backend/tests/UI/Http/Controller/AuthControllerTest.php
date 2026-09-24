<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class AuthControllerTest extends AuthenticatedApiTestCase
{
    public function testLoginReturnsTokensForValidCredentials(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'admin@tittawin.local',
                'password' => 'ChangeMe123!',
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame('admin@tittawin.local', $data['user']['email']);
    }

    public function testLoginRejectsInvalidCredentials(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'admin@tittawin.local',
                'password' => 'wrong-password',
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsCurrentUser(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'admin@tittawin.local',
                'password' => 'ChangeMe123!',
            ], JSON_THROW_ON_ERROR),
        );

        $login = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        $client->request(
            'GET',
            '/api/me',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['access_token']],
        );

        $this->assertResponseIsSuccessful();
        $me = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('admin@tittawin.local', $me['email']);
        $this->assertContains('identity.users.manage', $me['permissions']);
    }
}
