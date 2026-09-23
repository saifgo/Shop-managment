<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;

final class ReadinessChecker
{
    public function __construct(
        private Connection $connection,
        private ?CacheInterface $cache = null,
    ) {
    }

    /** @return array{ready: bool, checks: array<string, array{status: string, message?: string}>} */
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $ready = array_reduce(
            $checks,
            static fn (bool $carry, array $check): bool => $carry && $check['status'] === 'ok',
            true,
        );

        return ['ready' => $ready, 'checks' => $checks];
    }

    /** @return array{status: string, message?: string} */
    private function checkDatabase(): array
    {
        try {
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'error', 'message' => 'Database unreachable'];
        }
    }

    /** @return array{status: string, message?: string} */
    private function checkCache(): array
    {
        if ($this->cache === null) {
            return ['status' => 'ok', 'message' => 'Cache not configured'];
        }

        try {
            $key = 'readiness_'.bin2hex(random_bytes(8));
            $this->cache->get($key, static fn (): string => 'ok');

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'error', 'message' => 'Cache unreachable'];
        }
    }
}
