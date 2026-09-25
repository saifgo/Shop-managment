<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class DocumentPdfAndSharingTest extends AuthenticatedApiTestCase
{
    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testIssuedInvoiceDownloadsAsARealPdf(): void
    {
        $token = $this->login()['access_token'];
        $invoice = $this->issuedInvoice($token);

        $response = $this->raw('GET', '/api/documents/'.$invoice['id'].'/download', $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
        self::assertStringContainsString(
            'attachment; filename=Facture_'.$invoice['document_number'].'.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testShareLinkServesTheDocumentWithoutSigningInUntilRevoked(): void
    {
        $token = $this->login()['access_token'];
        $invoice = $this->issuedInvoice($token);
        self::assertNull($invoice['share_token']);

        $shared = $this->request($token, 'POST', '/api/documents/'.$invoice['id'].'/share');
        $shareToken = $shared['share_token'];
        self::assertIsString($shareToken);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $shareToken);
        // Sharing again keeps the same link.
        self::assertSame($shareToken, $this->request($token, 'POST', '/api/documents/'.$invoice['id'].'/share')['share_token']);

        $summary = $this->raw('GET', '/api/public/documents/'.$shareToken);
        self::assertSame(200, $summary->getStatusCode());
        self::assertStringContainsString('no-store', (string) $summary->headers->get('Cache-Control'));
        $body = json_decode((string) $summary->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Facture', $body['title']);
        self::assertSame($invoice['document_number'], $body['document_number']);
        self::assertSame($invoice['grand_total'], $body['grand_total']);

        $pdf = $this->raw('GET', '/api/public/documents/'.$shareToken.'/pdf');
        self::assertSame(200, $pdf->getStatusCode());
        self::assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        self::assertStringStartsWith('inline;', (string) $pdf->headers->get('Content-Disposition'));

        $download = $this->raw('GET', '/api/public/documents/'.$shareToken.'/pdf?download=1');
        self::assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));

        $preview = $this->raw('GET', '/api/public/documents/'.$shareToken.'/preview');
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('Facture N° '.$invoice['document_number'], (string) $preview->getContent());
        self::assertStringContainsString("default-src 'none'", (string) $preview->headers->get('Content-Security-Policy'));

        $revoked = $this->request($token, 'DELETE', '/api/documents/'.$invoice['id'].'/share');
        self::assertNull($revoked['share_token']);
        self::assertSame(404, $this->raw('GET', '/api/public/documents/'.$shareToken)->getStatusCode());
        self::assertSame(404, $this->raw('GET', '/api/public/documents/'.$shareToken.'/pdf')->getStatusCode());
    }

    public function testDraftsCannotBeShared(): void
    {
        $token = $this->login()['access_token'];
        $draft = $this->request($token, 'POST', '/api/documents', $this->invoicePayload($token, issue: false), 201);

        $this->request($token, 'POST', '/api/documents/'.$draft['id'].'/share', expectedStatus: 400);
    }

    public function testUnknownShareTokenIsNotFound(): void
    {
        self::assertSame(404, $this->raw('GET', '/api/public/documents/'.str_repeat('a', 32))->getStatusCode());
    }

    public function testCompanyProfileIsPrintedAndStampDutyAddedToNewTndInvoices(): void
    {
        $token = $this->login()['access_token'];
        $profile = $this->request($token, 'PUT', '/api/settings/company', [
            'name' => 'Rahma Bouaoun',
            'phone' => '+216 29 234 256',
            'email' => 'rahma@example.com',
            'tax_id' => '1845189/J/N/C/000',
            'address' => '',
            'bank_label' => '',
            'bank_account' => '17 002 0000003345772 96',
            'stamp_duty' => '1',
        ]);
        self::assertSame('Rahma Bouaoun', $profile['name']);
        self::assertNull($profile['address']);
        self::assertSame("Relevé d'identité postale", $profile['bank_label']);
        self::assertSame('1.0000', $profile['stamp_duty']);

        $invoice = $this->issuedInvoice($token);
        self::assertSame('1.0000', $invoice['stamp_duty']['amount']);
        self::assertSame('386.0000', $invoice['grand_total']['amount']);
        self::assertSame('386.0000', $invoice['amount_due']['amount']);

        // Stamp duty is a dinar tax: invoices in other currencies do not get it.
        $euroInvoice = $this->request($token, 'POST', '/api/documents', [
            ...$this->invoicePayload($token, issue: true),
            'currency' => 'EUR',
        ], 201);
        self::assertSame('0.0000', $euroInvoice['stamp_duty']['amount']);
        self::assertSame('385.0000', $euroInvoice['grand_total']['amount']);

        $shareToken = $this->request($token, 'POST', '/api/documents/'.$invoice['id'].'/share')['share_token'];
        $html = (string) $this->raw('GET', '/api/public/documents/'.$shareToken.'/preview')->getContent();
        self::assertStringContainsString('Rahma Bouaoun', $html);
        self::assertStringContainsString('1845189/J/N/C/000', $html);
        self::assertStringContainsString('17 002 0000003345772 96', $html);
        self::assertStringContainsString('Timbre', $html);
        self::assertStringContainsString("386\u{00A0}TND", $html);
    }

    public function testCompanyProfileRejectsInvalidValues(): void
    {
        $token = $this->login()['access_token'];

        $this->request($token, 'PUT', '/api/settings/company', ['email' => 'not-an-email'], 400);
        $this->request($token, 'PUT', '/api/settings/company', ['stamp_duty' => '-1'], 400);
    }

    public function testStampImageCanBeUploadedAndRemoved(): void
    {
        $token = $this->login()['access_token'];

        $uploaded = $this->uploadStamp($token, (string) base64_decode(self::PNG));
        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getContent());
        $profile = json_decode((string) $uploaded->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('data:image/png;base64,', $profile['stamp_image']);

        self::assertSame(422, $this->uploadStamp($token, 'not an image')->getStatusCode());

        $removed = $this->request($token, 'DELETE', '/api/settings/company/stamp');
        self::assertNull($removed['stamp_image']);
    }

    public function testPortalCustomersCannotReadOrChangeTheCompanyProfile(): void
    {
        $portalToken = $this->login('customer@tittawin.local')['access_token'];

        $this->request($portalToken, 'GET', '/api/settings/company', expectedStatus: 403);
        $this->request($portalToken, 'PUT', '/api/settings/company', ['name' => 'Hijacked'], 403);
    }

    /** @return array<string, mixed> */
    private function issuedInvoice(string $token): array
    {
        return $this->request($token, 'POST', '/api/documents', $this->invoicePayload($token, issue: true), 201);
    }

    /** @return array<string, mixed> */
    private function invoicePayload(string $token, bool $issue): array
    {
        return [
            'document_type' => 'INVOICE',
            'customer_id' => $this->findCustomerId($token, 'Atlas Hotel Group'),
            'issue' => $issue,
            'lines' => [['description' => 'Workshop', 'quantity' => '7', 'unit_price' => '55', 'tax_rate' => '0']],
        ];
    }

    private function findCustomerId(string $token, string $displayName): string
    {
        foreach ($this->request($token, 'GET', '/api/customers?per_page=100')['items'] as $customer) {
            if ($customer['display_name'] === $displayName) {
                return $customer['id'];
            }
        }

        self::fail(sprintf('Customer "%s" not seeded.', $displayName));
    }

    private function uploadStamp(string $token, string $contents): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'stamp');
        file_put_contents($path, $contents);

        $client = static::createClient();
        $client->request(
            'POST',
            '/api/settings/company/stamp',
            files: ['file' => new UploadedFile($path, 'stamp.png', 'image/png', null, true)],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client->getResponse();
    }

    /** Sends a request without asserting the status; omit the token to call as an anonymous visitor. */
    private function raw(string $method, string $uri, ?string $token = null): Response
    {
        $client = static::createClient();
        $client->request($method, $uri, server: $token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : []);

        return $client->getResponse();
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
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : ($method === 'POST' ? '{}' : null),
        );
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }
}
