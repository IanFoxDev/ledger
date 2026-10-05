<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * A posted transaction. Its postings debit and credit the same total in every currency.
 */
final readonly class Transaction
{
    /**
     * @param list<Posting> $postings
     * @param array<string, string|int|bool|null> $meta
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $hash,
        public string $type,
        public array $postings,
        public array $meta,
        public \DateTimeImmutable $createdAt,
        public ?int $reverses = null,
    ) {}

    /**
     * @return list<Posting>
     */
    public function postingsOf(string $account): array
    {
        return array_values(array_filter($this->postings, static fn(Posting $p): bool => $p->account === $account));
    }
}
