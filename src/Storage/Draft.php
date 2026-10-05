<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

use IanFoxDev\Ledger\Posting;

/**
 * A checked transaction that has not been written yet; the store gives it an id.
 */
final readonly class Draft
{
    /**
     * @param list<Posting> $postings
     * @param array<string, string|int|bool|null> $meta
     */
    public function __construct(
        public string $key,
        public string $hash,
        public string $type,
        public array $postings,
        public array $meta,
        public \DateTimeImmutable $createdAt,
        public ?int $reverses = null,
    ) {}
}
