<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum BackorderPolicy: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
