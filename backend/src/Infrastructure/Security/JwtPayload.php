<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final readonly class JwtPayload
{
    public function __construct(
        public string $sub,
        public string $companyId,
        public int $exp,
        public int $iat,
        public string $type,
    ) {
    }
}
