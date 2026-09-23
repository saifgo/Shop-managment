<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final readonly class Quantity
{
    private const SCALE = 4;

    private function __construct(
        private string $amount,
        private ?string $unit,
    ) {
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException(sprintf('Invalid quantity amount: %s', $amount));
        }

        if (bccomp($amount, '0', self::SCALE) < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative.');
        }
    }

    public static function of(string $amount, ?string $unit = null): self
    {
        return new self(self::normalize($amount), $unit);
    }

    public static function zero(?string $unit = null): self
    {
        return new self('0.0000', $unit);
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    public function add(self $other): self
    {
        $this->assertSameUnit($other);

        return new self(
            bcadd($this->amount, $other->amount, self::SCALE),
            $this->unit,
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameUnit($other);

        $result = bcsub($this->amount, $other->amount, self::SCALE);

        if (bccomp($result, '0', self::SCALE) < 0) {
            throw new \InvalidArgumentException('Quantity cannot become negative.');
        }

        return new self($result, $this->unit);
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0.0000', self::SCALE) === 0;
    }

    public function equals(self $other): bool
    {
        return $this->unit === $other->unit
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function compare(self $other): int
    {
        $this->assertSameUnit($other);

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function min(self $other): self
    {
        return $this->compare($other) <= 0 ? $this : $other;
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->unit !== $other->unit) {
            throw new \InvalidArgumentException('Cannot operate on quantities with different units.');
        }
    }

    private static function normalize(string $amount): string
    {
        if (!str_contains($amount, '.')) {
            return $amount.'.0000';
        }

        [$whole, $fraction] = explode('.', $amount, 2);
        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');

        return $whole.'.'.$fraction;
    }
}
