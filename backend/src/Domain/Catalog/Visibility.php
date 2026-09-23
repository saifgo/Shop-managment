<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum Visibility: string
{
    case Public = 'public';
    case Hidden = 'hidden';
    case Internal = 'internal';
}
