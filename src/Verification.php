<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * The result of recalculating the whole ledger from its postings.
 */
final readonly class Verification
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        public int $accounts,
        public int $transactions,
        public int $postings,
        public array $violations,
    ) {}

    public function ok(): bool
    {
        return $this->violations === [];
    }
}
