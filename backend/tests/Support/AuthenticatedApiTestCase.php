<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Console\SeedCatalogCommand;
use App\Infrastructure\Console\SeedIdentityCommand;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AuthenticatedApiTestCase extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // The login rate limiter counts attempts in the filesystem app cache, which
        // otherwise accumulates across tests and starts returning 429.
        static::getContainer()->get('cache.app')->clear();

        static::getContainer()->get(SeedIdentityCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        static::getContainer()->get(SeedCatalogCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        static::getContainer()->get(\App\Infrastructure\Console\SeedInventoryCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        static::getContainer()->get(\App\Infrastructure\Console\SeedProductionConfigCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );
    }

    /**
     * Tests create a fresh client per request; Symfony refuses to boot a second
     * kernel, so shut the previous one down first. The SQLite test database is
     * file-backed, so state survives the reboot.
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        static::ensureKernelShutdown();

        return parent::createClient($options, $server);
    }

    /**
     * @return array{access_token: string, user: array<string, mixed>}
     */
    protected function login(string $email = 'admin@tittawin.local', string $password = 'ChangeMe123!'): array
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(compact('email', 'password'), JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
    }
}
