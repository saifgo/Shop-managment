<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AuthRateLimiter
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function isAllowed(string $key, int $limit = 10, int $intervalSeconds = 60): bool
    {
        $cacheKey = 'auth_rate_'.hash('sha256', $key);

        $count = $this->cache->get($cacheKey, function (ItemInterface $item) use ($intervalSeconds): int {
            $item->expiresAfter($intervalSeconds);

            return 0;
        });

        if ($count >= $limit) {
            return false;
        }

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($count, $intervalSeconds): int {
            $item->expiresAfter($intervalSeconds);

            return $count + 1;
        });

        return true;
    }
}
