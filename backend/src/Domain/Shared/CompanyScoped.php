<?php

declare(strict_types=1);

namespace App\Domain\Shared;

interface CompanyScoped
{
    public function companyId(): EntityId;
}
