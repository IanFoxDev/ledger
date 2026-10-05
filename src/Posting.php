<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * A stored leg: its place in the account's chain and the balance right after it.
 */
final readonly class Posting
{
    public function __construct(
        public string $account,
        public int $sequence,
        public Side $side,
        public Amount $amount,
        public Amount $balanceAfter,
    ) {}
}
