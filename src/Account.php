<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * An account: a code, a type that fixes its normal side, a currency, and whether its
 * balance may go below zero. A user's wallet is a liability of the platform; money held
 * at a payment provider is an asset; fees earned are revenue.
 */
final readonly class Account
{
    public function __construct(
        public string $code,
        public AccountType $type,
        public string $currency,
        public bool $allowNegative = false,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:_.\/@-]{0,189}$/', $code) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Account code "%s" must be 1 to 190 characters: letters, digits and : _ . / @ -, starting with a letter or digit.',
                $code,
            ));
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{0,11}$/', $currency) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Currency "%s" must be 1 to 12 upper-case letters, digits or _, such as USD or CREDIT.',
                $currency,
            ));
        }
    }

    public static function asset(string $code, string $currency, bool $allowNegative = false): self
    {
        return new self($code, AccountType::Asset, $currency, $allowNegative);
    }

    public static function liability(string $code, string $currency, bool $allowNegative = false): self
    {
        return new self($code, AccountType::Liability, $currency, $allowNegative);
    }

    public static function equity(string $code, string $currency, bool $allowNegative = false): self
    {
        return new self($code, AccountType::Equity, $currency, $allowNegative);
    }

    public static function revenue(string $code, string $currency, bool $allowNegative = false): self
    {
        return new self($code, AccountType::Revenue, $currency, $allowNegative);
    }

    public static function expense(string $code, string $currency, bool $allowNegative = false): self
    {
        return new self($code, AccountType::Expense, $currency, $allowNegative);
    }

    /**
     * How a leg on this side changes the balance: +1 on the normal side, -1 on the other.
     */
    public function direction(Side $side): int
    {
        return $side === $this->type->normalSide() ? 1 : -1;
    }

    public function sameAs(self $other): bool
    {
        return $this->code === $other->code
            && $this->type === $other->type
            && $this->currency === $other->currency
            && $this->allowNegative === $other->allowNegative;
    }
}
