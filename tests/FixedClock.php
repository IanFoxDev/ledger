<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests;

use IanFoxDev\Ledger\Clock;

final class FixedClock implements Clock
{
    public function __construct(public \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-05 12:00:00', new \DateTimeZone('UTC'))) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
