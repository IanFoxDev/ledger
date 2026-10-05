<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

/**
 * The store refused a reversal because the original already has one.
 */
final class DuplicateReversal extends \RuntimeException {}
