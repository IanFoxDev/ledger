<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * An account with this code was opened with a different type, currency or negative-balance setting.
 */
final class AccountConflict extends LedgerException {}
