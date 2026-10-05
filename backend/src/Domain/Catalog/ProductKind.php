<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * What an item is to the workshop. Finished goods are made and sold; raw materials (clay, glazes,
 * pigments, kiln furniture, packaging) are bought and consumed by production, never sold.
 */
enum ProductKind: string
{
    case FinishedGood = 'finished_good';
    case RawMaterial = 'raw_material';

    public function isSellable(): bool
    {
        return $this === self::FinishedGood;
    }
}
