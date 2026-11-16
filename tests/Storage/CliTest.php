<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testVerifyExitsWithOneWhenSomethingIsWrong(): void
    {
        $pdo = Databases::fresh('LEDGER_PG_DSN');
        $ledger = new Ledger(new PdoStore($pdo));
        $ledger->open(Account::equity('world', 'USD', allowNegative: true));
        $ledger->open(Account::liability('user:42', 'USD'));
        $ledger->transfer('dep:1', 'world', 'user:42', 50_00);

        [$code, $out] = $this->ledgerCli('verify');
        self::assertSame(0, $code, $out);
        self::assertSame("2 accounts, 1 transactions, 2 postings\nok\n", $out);

        $pdo->exec("UPDATE ledger_postings SET amount = 6000, balance_after = 6000 WHERE account = 'user:42'");
        [$code, $out] = $this->ledgerCli('verify');
        self::assertSame(1, $code);
        self::assertStringContainsString("unbalanced         Transaction 1 (\"dep:1\"): debits and credits in USD differ by -1000.\n", $out);
        self::assertStringEndsWith("2 violations\n", $out);

        [$code, $out] = $this->ledgerCli('verify --json');
        self::assertSame(1, $code);
        $json = json_decode($out, true);
        self::assertIsArray($json);
        self::assertFalse($json['ok']);
    }

    public function testCreditsExpireRunsForEveryCustomer(): void
    {
        $pdo = Databases::fresh('LEDGER_PG_DSN');
        $ledger = new Ledger(new PdoStore($pdo), new FixedClock());
        $ledger->open(Account::asset('cash', 'USD'));
        $ledger->open(Account::revenue('revenue', 'USD'));
        $ledger->open(Account::revenue('breakage', 'USD'));
        $ledger->open(Account::expense('promo', 'USD'));
        foreach (['a', 'b', 'c'] as $customer) {
            $ledger->open(Account::liability("customer:$customer:credits", 'USD'));
            $ledger->credits()->grant("order:$customer", "customer:$customer:credits", 10_00, from: 'cash', revenue: 'revenue', breakage: 'breakage', expiresAt: new \DateTimeImmutable('2026-10-06 12:00:00 UTC'));
        }
        $ledger->credits()->grant('promo:a', 'customer:a:credits', 3_00, from: 'promo', revenue: 'revenue', breakage: 'promo', expiresAt: new \DateTimeImmutable('2026-10-06 12:00:00 UTC'));
        $ledger->credits()->grant('order:later', 'customer:a:credits', 5_00, from: 'cash', revenue: 'revenue', breakage: 'breakage', expiresAt: new \DateTimeImmutable('2026-10-08 12:00:00 UTC'));

        [$code, $out] = $this->ledgerCli('credits:expire --at=2026-10-05T12:00:00Z');
        self::assertSame([0, "0 lots expired\n"], [$code, $out]);

        [$code, $out] = $this->ledgerCli('credits:expire --at=2026-10-07 --limit=2');
        self::assertSame(1, $code, $out);
        self::assertStringStartsWith("2 lots expired\n", $out);
        self::assertStringEndsWith("stopped at --limit, lots may be left\n", $out);

        [$code, $out] = $this->ledgerCli('credits:expire --at=2026-10-07 --json');
        self::assertSame(0, $code, $out);
        $json = json_decode($out, true);
        self::assertIsArray($json);
        self::assertSame([2, true], [$json['lots'], $json['complete']]);

        // Without --at the clock decides: everything above expired long ago.
        [$code, $out] = $this->ledgerCli('credits:expire');
        self::assertSame([0, "1 lot expired\nbreakage 500\n"], [$code, $out]);
        self::assertSame('3500', (string) $ledger->balance('breakage'));
        self::assertSame('0', (string) $ledger->balance('promo'));
        self::assertSame(2, $this->ledgerCli('credits:expire --limit=0')[0]);
        self::assertTrue($ledger->verify()->ok());
    }

    public function testUsageErrorsExitWithTwo(): void
    {
        Databases::connect('LEDGER_PG_DSN');

        self::assertSame(2, $this->ledgerCli('check')[0]);
        self::assertSame(2, $this->ledgerCli('verify', '')[0]);
        self::assertSame(2, $this->ledgerCli('verify', 'pgsql:host=127.0.0.1;port=1;dbname=none')[0]);
    }

    /**
     * @return array{int, string}
     */
    private function ledgerCli(string $args, ?string $dsn = null): array
    {
        $dsn ??= (string) getenv('LEDGER_PG_DSN');
        $process = proc_open(
            \PHP_BINARY . ' ' . __DIR__ . '/../../bin/ledger ' . $args,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['LEDGER_DSN' => $dsn, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($process), $out];
    }
}
