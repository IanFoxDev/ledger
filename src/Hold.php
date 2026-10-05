<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * Money set aside on an account: moved to an account of its own until it is captured or
 * released. The id is the id of the transaction that placed the hold.
 */
final readonly class Hold
{
    public function __construct(
        public int $id,
        public string $key,
        public string $account,
        public string $holdAccount,
        public Amount $amount,
    ) {}
}
