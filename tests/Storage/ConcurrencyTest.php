<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Amount;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\PdoStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Eight processes write at once: transfers between the same few users, holds captured and
 * released, credits used, and keys that every process posts with the same content. No
 * money may appear or disappear, nothing may be applied twice, and the check must pass.
 */
final class ConcurrencyTest extends TestCase
{
    private const int PROCESSES = 8;
    private const int OPERATIONS = 150;
    private const int USERS = 6;

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'postgresql' => ['LEDGER_PG_DSN'];
        yield 'mysql' => ['LEDGER_MYSQL_DSN'];
    }

    #[DataProvider('databases')]
    public function testParallelWritersKeepTheBooks(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = new Ledger(new PdoStore($pdo));
        $ledger->open(Account::equity('world', 'USD', allowNegative: true));
        $ledger->open(Account::revenue('revenue', 'USD'));
        $ledger->open(Account::liability('customer:credits', 'USD'));
        for ($u = 0; $u < self::USERS; $u++) {
            $ledger->open(Account::liability("user:$u", 'USD'));
            $ledger->transfer("seed:$u", 'world', "user:$u", 50_00);
        }
        $ledger->credits()->grant('credits:1', 'customer:credits', 1_000_00, from: 'world', revenue: 'revenue', breakage: 'revenue');

        $processes = [];
        for ($w = 0; $w < self::PROCESSES; $w++) {
            $processes[$w] = proc_open(
                [\PHP_BINARY, __DIR__ . '/worker.php', (string) getenv($env), (string) $w, (string) self::OPERATIONS, (string) self::USERS],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$w],
            );
        }
        $totals = ['ok' => 0, 'insufficient' => 0, 'conflict' => 0, 'deadlock' => 0];
        foreach ($processes as $w => $process) {
            self::assertIsResource($process);
            $out = (string) stream_get_contents($pipes[$w][1]);
            $err = (string) stream_get_contents($pipes[$w][2]);
            self::assertSame(0, proc_close($process), "worker $w: $err");
            $count = json_decode($out, true);
            self::assertIsArray($count, $out . $err);
            foreach ($totals as $name => $sum) {
                self::assertIsInt($count[$name] ?? null, $out);
                $totals[$name] = $sum + $count[$name];
            }
        }
        fwrite(\STDERR, \sprintf("\n%s: %s\n", $env, json_encode($totals)));

        self::assertSame(0, $totals['conflict'], 'the same content under the same key must never conflict');
        self::assertSame(0, $totals['deadlock'], 'accounts are locked in order, so writers must not deadlock');
        self::assertGreaterThan(self::PROCESSES * self::OPERATIONS / 2, $totals['ok']);

        $verification = $ledger->verify();
        self::assertSame([], $verification->violations);

        // What world paid out is all there is: on users, in revenue, still on credits.
        $total = Amount::zero();
        for ($u = 0; $u < self::USERS; $u++) {
            $total = $total->plus($ledger->balance("user:$u"))->plus($ledger->held("user:$u"));
        }
        $total = $total->plus($ledger->balance('revenue'))->plus($ledger->credits()->available('customer:credits'));
        self::assertSame((string) $ledger->balance('world')->negated(), (string) $total);

        $shared = $pdo->query("SELECT COUNT(*) FROM ledger_transactions WHERE idempotency_key LIKE 'shared:%'");
        self::assertNotFalse($shared);
        self::assertLessThanOrEqual(self::OPERATIONS, (int) $shared->fetchColumn());
    }

    /**
     * Accounts with no postings yet, which is what every new hold and lot is. In MySQL
     * under REPEATABLE READ, reading the end of an empty chain with a lock takes a gap
     * lock that two inserts then wait on in a circle.
     */
    #[DataProvider('databases')]
    public function testParallelWritersOnNewAccountsDoNotDeadlock(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = new Ledger(new PdoStore($pdo));
        for ($a = 0; $a < 200; $a++) {
            $ledger->open(Account::liability("acc:$a", 'USD', allowNegative: true));
        }

        $processes = [];
        for ($w = 0; $w < self::PROCESSES; $w++) {
            $code = <<<'PHP'
                require $argv[1];
                [$dsn, $w] = [$argv[2], (int) $argv[3]];
                mt_srand($w);
                $ledger = new IanFoxDev\Ledger\Ledger(new IanFoxDev\Ledger\Storage\PdoStore(new PDO($dsn)));
                for ($i = 0; $i < 60; $i++) {
                    $a = mt_rand(0, 199);
                    $ledger->transfer("t:$w:$i", "acc:$a", 'acc:' . (($a + mt_rand(1, 199)) % 200), mt_rand(1, 100));
                }
                PHP;
            $processes[$w] = proc_open(
                [\PHP_BINARY, '-r', $code, __DIR__ . '/../../vendor/autoload.php', (string) getenv($env), (string) $w],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$w],
            );
        }
        foreach ($processes as $w => $process) {
            self::assertIsResource($process);
            stream_get_contents($pipes[$w][1]);
            $err = (string) stream_get_contents($pipes[$w][2]);
            self::assertSame(0, proc_close($process), "worker $w: " . substr($err, 0, 300));
        }

        self::assertSame([], $ledger->verify()->violations);
        self::assertSame(self::PROCESSES * 60 * 2, $ledger->verify()->postings);
    }
}
