<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * Told about every transaction the ledger writes, inside the database transaction that
 * writes it. What the listener stores commits or rolls back with the postings, and an
 * exception it throws undoes the write.
 *
 * It is not called for a retry that returns a stored transaction: one call per row in
 * ledger_transactions.
 */
interface TransactionListener
{
    public function posted(Transaction $transaction): void;
}
