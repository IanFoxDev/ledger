<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * The transaction already has a reversal; a transaction is reversed at most once.
 */
final class AlreadyReversed extends LedgerException {}
