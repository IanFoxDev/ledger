<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use PHPUnit\Framework\TestCase;

/**
 * Fresh ledger tables in PostgreSQL or MySQL, when LEDGER_PG_DSN or LEDGER_MYSQL_DSN is
 * set (make databases-up starts both); otherwise the test is skipped.
 */
final class Databases
{
    public static function connect(string $env): \PDO
    {
        $dsn = getenv($env);
        if (!\is_string($dsn) || $dsn === '') {
            TestCase::markTestSkipped("$env not set");
        }
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public static function fresh(string $env): \PDO
    {
        $pdo = self::connect($env);
        foreach (['ledger_postings', 'ledger_transactions', 'ledger_accounts'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
        $schema = file_get_contents(__DIR__ . '/../../schema/' . ($env === 'LEDGER_PG_DSN' ? 'postgresql.sql' : 'mysql.sql'));
        if ($schema === false) {
            throw new \RuntimeException('schema not found');
        }
        // PDO MySQL runs one statement per exec() call unless emulated; split to be safe.
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema) ?? ''))) as $statement) {
            $pdo->exec($statement);
        }

        return $pdo;
    }
}
