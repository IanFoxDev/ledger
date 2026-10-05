<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
