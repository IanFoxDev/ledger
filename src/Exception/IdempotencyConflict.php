<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * The idempotency key was already used for a transaction with different content.
 */
final class IdempotencyConflict extends LedgerException {}
