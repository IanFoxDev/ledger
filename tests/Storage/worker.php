<?php

declare(strict_types=1);

// One process of ConcurrencyTest: random transfers, holds, credit usage and shared keys
// that every process posts with the same content. Prints its counters as JSON.

use IanFoxDev\Ledger\Exception\IdempotencyConflict;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\PdoStore;

require __DIR__ . '/../../vendor/autoload.php';

[, $dsn, $worker, $operations, $users] = $argv;
$worker = (int) $worker;
$users = (int) $users;
mt_srand(1000 + $worker);
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ledger = new Ledger(new PdoStore($pdo));
$credits = $ledger->credits();
$count = ['ok' => 0, 'insufficient' => 0, 'conflict' => 0, 'deadlock' => 0];
$shared = range(0, (int) $operations - 1);
shuffle($shared);

for ($i = 0; $i < (int) $operations; $i++) {
    $a = mt_rand(0, $users - 1);
    $b = ($a + mt_rand(1, $users - 1)) % $users;
    $amount = mt_rand(1, 3_000);
    $k = $shared[$i];
    try {
        match (mt_rand(0, 4)) {
            0, 1 => $ledger->transfer("w$worker:$i", "user:$a", "user:$b", $amount),
            // Every process posts these keys with the same content: one of them wins.
            2 => $ledger->transfer("shared:$k", 'user:' . ($k % $users), 'user:' . (($k + 1) % $users), $k % 500 + 1),
            3 => (static function () use ($ledger, $worker, $i, $a, $amount): void {
                $hold = $ledger->hold("w$worker:$i:hold", "user:$a", $amount);
                $ledger->capture("w$worker:$i:capture", $hold, 'revenue', intdiv($amount, 3) + 1);
                $ledger->release("w$worker:$i:release", $hold);
            })(),
            4 => $credits->consume("w$worker:$i:job", 'customer:credits', mt_rand(1, 50)),
        };
        $count['ok']++;
    } catch (InsufficientFunds) {
        $count['insufficient']++;
    } catch (IdempotencyConflict) {
        $count['conflict']++;
    } catch (PDOException $e) {
        // 40P01 PostgreSQL deadlock, 40001 MySQL deadlock (1213).
        if (!in_array($e->getCode(), ['40P01', '40001'], true)) {
            throw $e;
        }
        $count['deadlock']++;
    }
}

echo json_encode($count), "\n";
