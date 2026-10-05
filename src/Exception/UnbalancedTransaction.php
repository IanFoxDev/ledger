<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * The debits of a transaction do not equal its credits in some currency.
 */
final class UnbalancedTransaction extends LedgerException {}
