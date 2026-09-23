<?php

declare(strict_types=1);

namespace App\Domain\Production;

use App\Domain\Shared\Quantity;

final class StageQuantityReconciler
{
    public function reconcile(
        Quantity $input,
        Quantity $acceptedOutput,
        Quantity $loss,
        ReconciliationMode $mode,
    ): void {
        if ($input->isZero()) {
            throw new \DomainException('Input quantity must be greater than zero.');
        }

        $sum = $acceptedOutput->add($loss);

        match ($mode) {
            ReconciliationMode::Strict => $this->assertStrict($input, $sum),
            ReconciliationMode::Flexible => $this->assertFlexible($input, $sum),
            ReconciliationMode::Conversion => $this->assertConversion($input, $sum),
        };
    }

    private function assertStrict(Quantity $input, Quantity $sum): void
    {
        if (!$input->equals($sum)) {
            throw new \DomainException(sprintf(
                'Strict reconciliation failed: input %s must equal accepted output + loss (%s).',
                $input->amount(),
                $sum->amount(),
            ));
        }
    }

    private function assertFlexible(Quantity $input, Quantity $sum): void
    {
        if (bccomp($sum->amount(), $input->amount(), 4) > 0) {
            throw new \DomainException(sprintf(
                'Flexible reconciliation failed: accepted output + loss (%s) cannot exceed input (%s).',
                $sum->amount(),
                $input->amount(),
            ));
        }
    }

    private function assertConversion(Quantity $input, Quantity $sum): void
    {
        if ($sum->isZero()) {
            throw new \DomainException('Conversion reconciliation requires a non-zero accepted output or loss.');
        }
    }
}
