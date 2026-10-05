<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Amount;

/**
 * The end of an account's chain: the last sequence number and the balance after it.
 */
final readonly class Head
{
    public function __construct(
        public Account $account,
        public int $sequence,
        public Amount $balance,
    ) {}
}
