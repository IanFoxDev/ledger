<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

enum Side: string
{
    case Debit = 'D';
    case Credit = 'C';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
