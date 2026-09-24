<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Tests\Support\AuthenticatedApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductMediaTest extends AuthenticatedApiTestCase
{
    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testAdminCanUploadServeMakePrimaryAndDeletePictures(): void
    {
        $token = $this->login()['access_token'];
        $productId = $this->firstProductId($token);

        $first = $this->upload($token, $productId, base64_decode(self::PNG), 'Front view');
        self::assertResponseStatusCodeSame(201);
        $second = $this->upload($token, $productId, base64_decode(self::PNG));
        self::assertResponseStatusCodeSame(201);

        $uploaded = array_values(array_filter($second['media'], static fn (array $m) => str_starts_with($m['url'], '/api/media/')));
        self::assertCount(2, $uploaded);
        $firstMedia = $this->mediaByAlt($first['media'], 'Front view');
        $secondMedia = $uploaded[0]['id'] === $firstMedia['id'] ? $uploaded[1] : $uploaded[0];

        // Served without a bearer token so <img> tags can load it.
        $client = static::createClient();
        $client->request('GET', $firstMedia['url']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(base64_decode(self::PNG), $client->getResponse()->getContent());

        $client = static::createClient();
        $client->request(
            'POST',
            sprintf('/api/products/%s/media/%s/primary', $productId, $secondMedia['id']),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $detail = $this->decode($client->getResponse()->getContent());
        $primary = array_values(array_filter($detail['media'], static fn (array $m) => $m['is_primary']));
        self::assertCount(1, $primary);
        self::assertSame($secondMedia['id'], $primary[0]['id']);
        self::assertSame($secondMedia['url'], $detail['primary_image_url']);

        $client = static::createClient();
        $client->request(
            'DELETE',
            sprintf('/api/products/%s/media/%s', $productId, $secondMedia['id']),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $detail = $this->decode($client->getResponse()->getContent());
        self::assertNotContains($secondMedia['id'], array_column($detail['media'], 'id'));
        // Removing the main picture promotes another one.
        self::assertCount(1, array_filter($detail['media'], static fn (array $m) => $m['is_primary']));

        $client = static::createClient();
        $client->request('GET', $secondMedia['url']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNonImageUploadIsRejected(): void
    {
        $token = $this->login()['access_token'];
        $this->upload($token, $this->firstProductId($token), '<?php echo "hi";');

        self::assertResponseStatusCodeSame(422);
    }

    public function testPortalUserCannotUpload(): void
    {
        $adminToken = $this->login()['access_token'];
        $productId = $this->firstProductId($adminToken);
        $portalToken = $this->login('customer@tittawin.local')['access_token'];

        $this->upload($portalToken, $productId, base64_decode(self::PNG));

        self::assertResponseStatusCodeSame(403);
    }

    private function firstProductId(string $token): string
    {
        $client = static::createClient();
        $client->request('GET', '/api/products', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->decode($client->getResponse()->getContent())['items'][0]['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $token, string $productId, string $contents, ?string $altText = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'media');
        file_put_contents($path, $contents);

        $client = static::createClient();
        $client->request(
            'POST',
            sprintf('/api/products/%s/media', $productId),
            parameters: $altText !== null ? ['alt_text' => $altText] : [],
            files: ['file' => new UploadedFile($path, 'picture.png', 'image/png', null, true)],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $this->decode($client->getResponse()->getContent());
    }

    /**
     * @param list<array<string, mixed>> $media
     *
     * @return array<string, mixed>
     */
    private function mediaByAlt(array $media, string $altText): array
    {
        foreach ($media as $item) {
            if ($item['alt_text'] === $altText) {
                return $item;
            }
        }

        self::fail('Uploaded picture not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $content): array
    {
        return json_decode($content ?: '', true, 512, JSON_THROW_ON_ERROR);
    }
}
