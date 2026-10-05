<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Storage\Store;
use IanFoxDev\Ledger\Tests\CreditsTest;

final class PostgresCreditsTest extends CreditsTest
{
    protected function store(): Store
    {
        return new PdoStore(Databases::fresh('LEDGER_PG_DSN'));
    }
}
