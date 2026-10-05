<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * The transaction does not exist or is of a kind that cannot be reversed, such as a reversal.
 */
final class NotReversible extends LedgerException {}
