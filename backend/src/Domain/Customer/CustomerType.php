<?php

declare(strict_types=1);

namespace App\Domain\Customer;

enum CustomerType: string
{
    case Person = 'person';
    case Company = 'company';
    case Association = 'association';
}
