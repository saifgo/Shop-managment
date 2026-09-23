<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;

final class ReportControllerTest extends AuthenticatedApiTestCase
{
    public function testAdminDashboardReturnsKpis(): void
    {
        $admin = $this->login();
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/dashboard/admin',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('pending_orders', $payload);
        self::assertArrayHasKey('low_stock_variants', $payload);
    }

    public function testPortalDashboardReturnsSummary(): void
    {
        $portal = $this->login('customer@tittawin.local');
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/dashboard/portal',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$portal['access_token']],
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('active_orders', $payload);
        self::assertArrayHasKey('outstanding_balance', $payload);
        self::assertArrayHasKey('recent_invoices', $payload);
    }

    public function testReportsEndpointsAreAvailable(): void
    {
        $admin = $this->login();
        $client = static::createClient();

        foreach ([
            '/api/reports/sales',
            '/api/reports/margin',
            '/api/reports/stock',
            '/api/reports/production-yield',
            '/api/reports/receivables-aging',
            '/api/reports/payables-aging',
        ] as $path) {
            $client->request(
                'GET',
                $path,
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['access_token']],
            );
            self::assertResponseIsSuccessful(sprintf('Expected success for %s', $path));
        }
    }
}
