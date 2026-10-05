<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

use Brick\Math\BigInteger;

/**
 * An integer number of minor units (cents, wei, credits). Signed, because a balance of an
 * account that may go negative can be below zero; the amount of a leg is always positive.
 */
final readonly class Amount implements \Stringable, \JsonSerializable
{
    private function __construct(public BigInteger $value) {}

    public static function of(int|string|BigInteger $value): self
    {
        if ($value instanceof BigInteger) {
            return new self($value);
        }
        if (\is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Amount "%s" is not an integer number of minor units. 10.50 USD is 1050.',
                $value,
            ));
        }

        return new self(BigInteger::of($value));
    }

    public static function zero(): self
    {
        return new self(BigInteger::zero());
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function equals(self $other): bool
    {
        return $this->value->isEqualTo($other->value);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): string
    {
        return (string) $this->value;
    }
}
