<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $data['status']);
        $this->assertSame('Tittawin Management System', $data['service']);
        $this->assertSame('0.1.0', $data['version']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testHealthEndpointIncludesCorrelationId(): void
    {
        $client = static::createClient();
        $correlationId = Uuid::v4()->toRfc4122();

        $client->request('GET', '/api/health', server: ['HTTP_X_CORRELATION_ID' => $correlationId]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Correlation-ID', $correlationId);
    }

    public function testReadinessEndpointChecksDependencies(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ready');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ready', $data['status']);
        $this->assertArrayHasKey('checks', $data);
        $this->assertSame('ok', $data['checks']['database']['status']);
    }
}
