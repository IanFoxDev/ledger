<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

/**
 * The store refused a transaction because its idempotency key is already taken.
 */
final class DuplicateKey extends \RuntimeException {}
