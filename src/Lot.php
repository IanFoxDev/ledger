<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * A batch of credits granted at once, with its own expiry, kept on an account of its own.
 * The id is the id of the grant transaction.
 */
final readonly class Lot
{
    public function __construct(
        public int $id,
        public string $key,
        public string $account,
        public string $lotAccount,
        public Amount $granted,
        public Amount $remaining,
        public ?\DateTimeImmutable $expiresAt,
        public string $revenue,
        public string $breakage,
    ) {}

    public function isExpiredAt(\DateTimeImmutable $at): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $at;
    }
}
