<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * Assets and expenses grow with debits; liabilities, equity and revenue with credits.
     */
    public function normalSide(): Side
    {
        return match ($this) {
            self::Asset, self::Expense => Side::Debit,
            self::Liability, self::Equity, self::Revenue => Side::Credit,
        };
    }
}
