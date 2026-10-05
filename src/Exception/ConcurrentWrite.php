<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * Another transaction wrote to the account after this database transaction took its
 * snapshot, so the next posting number was already taken. Nothing is applied: the database
 * refused the row. Roll back and retry, and run ledger writes at READ COMMITTED.
 */
final class ConcurrentWrite extends LedgerException {}
