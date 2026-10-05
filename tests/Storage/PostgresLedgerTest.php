<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Storage\Store;
use IanFoxDev\Ledger\Tests\LedgerTest;

final class PostgresLedgerTest extends LedgerTest
{
    protected function store(): Store
    {
        return new PdoStore(Databases::fresh('LEDGER_PG_DSN'));
    }
}
