<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * Thrown when the ledger refuses a write. No money has moved when it is thrown; inside your
 * own transaction, roll it back before using the connection again.
 */
abstract class LedgerException extends \RuntimeException {}
