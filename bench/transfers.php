<?php

declare(strict_types=1);

// Transfers per second with several writer processes.
//
//   php bench/transfers.php DSN [processes=8] [transfers=500] [accounts=1000]
//
// Recreates the ledger tables in the database DSN points at. accounts=2 is the worst case:
// every transfer locks the same two rows. Each transfer is its own database transaction.

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\PdoStore;

require __DIR__ . '/../vendor/autoload.php';

$dsn = $argv[1] ?? exit("usage: php bench/transfers.php DSN [processes] [transfers] [accounts]\n");
$processes = (int) ($argv[2] ?? 8);
$transfers = (int) ($argv[3] ?? 500);
$accounts = (int) ($argv[4] ?? 1000);

if (($argv[5] ?? '') === '--worker') {
    $worker = (int) $argv[6];
    mt_srand($worker);
    $ledger = new Ledger(new PdoStore(new PDO($dsn)));
    for ($i = 0; $i < $transfers; $i++) {
        $a = mt_rand(0, $accounts - 1);
        $b = ($a + mt_rand(1, $accounts - 1)) % $accounts;
        $ledger->transfer("bench:$worker:$i", "acc:$a", "acc:$b", mt_rand(1, 100));
    }
    exit(0);
}

$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
foreach (['ledger_postings', 'ledger_transactions', 'ledger_accounts'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}
$schema = (string) file_get_contents(__DIR__ . '/../schema/' . ($mysql ? 'mysql.sql' : 'postgresql.sql'));
foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^--.*$/m', '', $schema)))) as $statement) {
    $pdo->exec($statement);
}
$ledger = new Ledger(new PdoStore($pdo));
$ledger->open(Account::equity('world', 'USD', allowNegative: true));
for ($a = 0; $a < $accounts; $a++) {
    $ledger->open(Account::liability("acc:$a", 'USD', allowNegative: true));
}

$started = hrtime(true);
$running = [];
for ($w = 0; $w < $processes; $w++) {
    $running[] = proc_open([PHP_BINARY, __FILE__, $dsn, (string) $processes, (string) $transfers, (string) $accounts, '--worker', (string) $w], [], $pipes);
}
foreach ($running as $process) {
    if ($process === false || proc_close($process) !== 0) {
        exit("a worker failed\n");
    }
}
$seconds = (hrtime(true) - $started) / 1e9;
$total = $processes * $transfers;

$check = $ledger->verify();
printf(
    "%s, %d processes, %d accounts: %d transfers in %.1f s, %d per second; verify: %s\n",
    $mysql ? 'mysql' : 'postgresql',
    $processes,
    $accounts,
    $total,
    $seconds,
    $total / $seconds,
    $check->ok() ? 'ok' : count($check->violations) . ' violations',
);
