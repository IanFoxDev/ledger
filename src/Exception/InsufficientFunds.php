<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Exception;

/**
 * A transaction would take an account that must not go negative below zero.
 */
final class InsufficientFunds extends LedgerException {}
