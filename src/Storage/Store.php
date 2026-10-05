<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Transaction;

/**
 * Where the ledger keeps accounts and transactions. Rows are only inserted.
 */
interface Store
{
    /**
     * Runs $fn in a database transaction. If one is already open, joins it: the caller
     * commits or rolls back. Otherwise commits when $fn returns and rolls back when it throws.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed;

    public function account(string $code): ?Account;

    /**
     * Inserts the account unless one with the same code exists; returns whether it inserted.
     */
    public function addAccount(Account $account): bool;

    /**
     * Locks the given accounts for the rest of the database transaction, in the given
     * order, and returns the heads of those that exist, keyed by code.
     *
     * @param list<string> $codes sorted
     * @return array<string, Head>
     */
    public function lock(array $codes): array;

    /**
     * The head of an account's chain without a lock, or null if the account does not exist.
     */
    public function head(string $code): ?Head;

    /**
     * Reads the latest committed state, not a snapshot: called after lock().
     */
    public function transactionByKey(string $key): ?Transaction;

    public function transaction(int $id): ?Transaction;

    /**
     * @throws DuplicateKey when the idempotency key is already taken
     */
    public function append(Draft $draft): Transaction;
}
