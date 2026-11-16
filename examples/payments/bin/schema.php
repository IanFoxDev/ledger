<?php

declare(strict_types=1);

// Creates the ledger tables on first start and opens the provider's cash account.

use Example\Payments\Books;
use IanFoxDev\Ledger\Account;

require __DIR__ . '/../vendor/autoload.php';

$dsn = (string) getenv('DATABASE_DSN');
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo->query("SELECT to_regclass('ledger_transactions')")->fetchColumn() === null) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../vendor/ianfoxdev/ledger/schema/postgresql.sql'));
    echo "ledger tables created\n";
}
Books::connect($dsn)->ledger->open(Account::asset(Books::CASH, Books::CURRENCY));
