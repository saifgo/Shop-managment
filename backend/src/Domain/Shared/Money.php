<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final readonly class Money
{
    private const SCALE = 4;

    private function __construct(
        private string $amount,
        private string $currency,
    ) {
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException(sprintf('Invalid monetary amount: %s', $amount));
        }

        if ($currency === '' || strlen($currency) !== 3) {
            throw new \InvalidArgumentException(sprintf('Invalid currency code: %s', $currency));
        }
    }

    public static function of(string $amount, string $currency): self
    {
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException(sprintf('Invalid monetary amount: %s', $amount));
        }

        return new self(self::normalize($amount), strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self('0.0000', strtoupper($currency));
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcadd($this->amount, $other->amount, self::SCALE),
            $this->currency,
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcsub($this->amount, $other->amount, self::SCALE),
            $this->currency,
        );
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0.0000', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0.0000', self::SCALE) < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot operate on amounts with different currencies.');
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
