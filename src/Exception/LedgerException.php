<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * Thrown when the ledger refuses a write. Nothing has been written when it is thrown.
 */
abstract class LedgerException extends \RuntimeException {}
