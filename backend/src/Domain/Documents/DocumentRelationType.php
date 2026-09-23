<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentRelationType: string
{
    case DerivedFrom = 'DERIVED_FROM';
    case CreditFor = 'CREDIT_FOR';
    case Cancels = 'CANCELS';
}
