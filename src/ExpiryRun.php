<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * What one call of Credits::expireAll() did.
 */
final readonly class ExpiryRun
{
    /**
     * @param list<Transaction> $transactions one per lot that had credits left
     * @param string|null $next pass it as $after to continue; null when every lot was looked at
     */
    public function __construct(
        public array $transactions,
        public ?string $next,
    ) {}

    public function complete(): bool
    {
        return $this->next === null;
    }
}
