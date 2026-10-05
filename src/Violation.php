<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * One thing the check found wrong, with the account or transaction it is about.
 */
final readonly class Violation
{
    public const string UNBALANCED = 'unbalanced';
    public const string HASH_MISMATCH = 'hash_mismatch';
    public const string CHAIN_GAP = 'chain_gap';
    public const string BALANCE_MISMATCH = 'balance_mismatch';
    public const string NEGATIVE_BALANCE = 'negative_balance';
    public const string REVERSAL_MISMATCH = 'reversal_mismatch';
    public const string NO_POSTINGS = 'no_postings';

    public function __construct(
        public string $kind,
        public string $message,
        public ?string $account = null,
        public ?int $transaction = null,
    ) {}
}
