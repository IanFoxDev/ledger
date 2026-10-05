<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * One line of a transaction to be posted: debit or credit an account by a positive amount.
 */
final readonly class Leg
{
    public Amount $amount;

    public function __construct(
        public string $account,
        public Side $side,
        int|string|Amount $amount,
    ) {
        $this->amount = $amount instanceof Amount ? $amount : Amount::of($amount);
        if (!$this->amount->isPositive()) {
            throw new \InvalidArgumentException(\sprintf(
                'The amount of a leg on "%s" must be positive, got %s.',
                $account,
                $this->amount,
            ));
        }
    }

    public static function debit(string $account, int|string|Amount $amount): self
    {
        return new self($account, Side::Debit, $amount);
    }

    public static function credit(string $account, int|string|Amount $amount): self
    {
        return new self($account, Side::Credit, $amount);
    }
}
