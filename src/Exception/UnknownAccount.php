<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * An account the transaction refers to has not been opened.
 */
final class UnknownAccount extends LedgerException {}
