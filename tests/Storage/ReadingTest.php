<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Tests\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the queries of docs/reading.md as they are printed there. A schema change that
 * breaks a documented query fails here before it breaks somebody's nightly job.
 */
final class ReadingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'postgresql' => ['LEDGER_PG_DSN'];
        yield 'mysql' => ['LEDGER_MYSQL_DSN'];
    }

    #[DataProvider('databases')]
    public function testBalances(string $env): void
    {
        $pdo = $this->history($env);

        self::assertSame([['balance_after' => '3763']], $this->rows($pdo, 'Balance of an account', ['account' => 'user:42']));
        self::assertSame([], $this->rows($pdo, 'Balance of an account', ['account' => 'revenue:breakage']));

        $at = 'Balance at a moment';
        self::assertSame([], $this->rows($pdo, $at, ['account' => 'user:42', 'at' => '2026-10-05 12:00:00']));
        self::assertSame([['balance_after' => '5000']], $this->rows($pdo, $at, ['account' => 'user:42', 'at' => '2026-10-06 12:00:00']));
        self::assertSame([['balance_after' => '3000']], $this->rows($pdo, $at, ['account' => 'user:42', 'at' => '2026-10-06 12:00:01']));
    }

    #[DataProvider('databases')]
    public function testBalancesOfManyAccounts(string $env): void
    {
        $pdo = $this->history($env);
        $many = 'Balances of many accounts';

        $all = array_column($this->rows($pdo, $many, ['pattern' => '%']), 'balance', 'code');
        self::assertSame('9000', $all['cash:psp']);
        self::assertSame('3763', $all['user:42']);
        self::assertSame('237', $all['revenue']);
        self::assertSame('0', $all['revenue:breakage']);
        self::assertCount(7, $all);

        $holds = $this->rows($pdo, $many, ['pattern' => 'user:42@hold:%']);
        self::assertCount(1, $holds);
        self::assertSame(['liability', 'USD', '0'], [$holds[0]['type'], $holds[0]['currency'], $holds[0]['balance']]);

        $lots = $this->rows($pdo, $many, ['pattern' => 'customer:acme:credits@lot:%']);
        self::assertCount(1, $lots);
        self::assertStringStartsWith('customer:acme:credits@lot:20261106T120000Z:', (string) $lots[0]['code']);
        self::assertSame('5000', $lots[0]['balance']);
    }

    #[DataProvider('databases')]
    public function testStatementPages(string $env): void
    {
        $pdo = $this->history($env);
        $statement = 'Statement of an account';

        $first = $this->rows($pdo, $statement, ['account' => 'user:42', 'before' => \PHP_INT_MAX]);
        self::assertSame(['6', '5', '4', '3', '2', '1'], array_map(strval(...), array_column($first, 'sequence')));
        self::assertSame(['psp:re_1', 'hold.release', 'D', '1000', '3763'], [
            $first[0]['idempotency_key'], $first[1]['type'], $first[0]['side'], $first[0]['amount'], $first[0]['balance_after'],
        ]);

        $next = $this->rows($pdo, $statement, ['account' => 'user:42', 'before' => 3]);
        self::assertSame(['buy:1', 'psp:ch_1001'], array_column($next, 'idempotency_key'));
    }

    #[DataProvider('databases')]
    public function testOneBusinessEvent(string $env): void
    {
        $pdo = $this->history($env);

        $rows = $this->rows($pdo, 'Everything one business event wrote', ['key' => 'buy:1']);
        // By position: the postings were applied in the order of account code.
        self::assertSame(['revenue', 'user:42'], array_column($rows, 'account'));
        self::assertSame(['C', 'D'], array_column($rows, 'side'));
        self::assertSame(['transfer', '2000', null], [$rows[0]['type'], $rows[0]['amount'], $rows[0]['reverses']]);

        $undone = $this->rows($pdo, 'Everything one business event wrote', ['id' => (int) $rows[0]['id']], 1);
        self::assertSame(['buy:1:refund'], array_column($undone, 'idempotency_key'));
    }

    #[DataProvider('databases')]
    public function testTurnoverAndTrialBalance(string $env): void
    {
        $pdo = $this->history($env);

        $day = $this->rows($pdo, 'Turnover of each account in a period', ['from' => '2026-10-06 00:00:00', 'to' => '2026-10-07 00:00:00']);
        self::assertSame([
            ['account' => 'revenue', 'debits' => '0', 'credits' => '2000'],
            ['account' => 'user:42', 'debits' => '2000', 'credits' => '0'],
        ], $day);

        $trial = $this->rows($pdo, 'Trial balance');
        self::assertSame(['USD'], array_column($trial, 'currency'));
        self::assertSame($trial[0]['debits'], $trial[0]['credits']);
        self::assertSame('15600', $trial[0]['debits']);
    }

    #[DataProvider('databases')]
    public function testMovementsForReconciliation(string $env): void
    {
        $pdo = $this->history($env);
        $movements = 'Movements on one account, for reconciliation and exports';

        $rows = $this->rows($pdo, $movements, ['account' => 'cash:psp', 'after' => 0]);
        self::assertSame(
            [['psp:ch_1001', '5000'], ['psp:re_1', '-1000'], ['grant:1', '5000']],
            array_map(static fn(array $row): array => [$row['idempotency_key'], $row['amount']], $rows),
        );

        $rest = $this->rows($pdo, $movements, ['account' => 'cash:psp', 'after' => 2]);
        self::assertSame(['grant:1'], array_column($rest, 'idempotency_key'));
    }

    #[DataProvider('databases')]
    public function testMeta(string $env): void
    {
        $pdo = $this->history($env);

        // The block has one statement per database, each under a comment naming it.
        $name = $env === 'LEDGER_PG_DSN' ? 'PostgreSQL' : 'MySQL';
        if (preg_match('/^-- ' . $name . '\n(.+);$/m', self::query('Meta'), $match) !== 1) {
            self::fail("docs/reading.md: no $name statement under Meta");
        }
        $statement = $pdo->query($match[1]);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        self::assertSame(['A-1'], array_values(array_filter($statement->fetchAll(\PDO::FETCH_COLUMN, 1))));
    }

    /**
     * @param array<string, string|int> $parameters
     * @return list<array<string, string|null>>
     */
    private function rows(\PDO $pdo, string $heading, array $parameters = [], int $block = 0): array
    {
        $statement = $pdo->prepare(self::query($heading, $block));
        $statement->execute($parameters);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            $line = [];
            foreach ($row as $column => $value) {
                $line[(string) $column] = $value === null ? null : (\is_scalar($value) ? (string) $value : '');
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * The n-th sql block under a "## heading" of docs/reading.md.
     */
    private static function query(string $heading, int $block = 0): string
    {
        $document = (string) file_get_contents(__DIR__ . '/../../docs/reading.md');
        $sections = preg_split('/^## /m', $document) ?: [];
        foreach ($sections as $section) {
            if (!str_starts_with($section, $heading . "\n")) {
                continue;
            }
            preg_match_all('/^```sql\n(.*?)^```$/ms', $section, $blocks);
            self::assertArrayHasKey($block, $blocks[1], "docs/reading.md: \"$heading\" has no sql block $block");

            return trim($blocks[1][$block]);
        }
        self::fail("docs/reading.md has no section \"$heading\"");
    }

    /**
     * A deposit from a provider, a purchase and its refund, a hold captured in part, a
     * refund to the provider and a grant of credits, over three days.
     */
    private function history(string $env): \PDO
    {
        $pdo = Databases::fresh($env);
        $clock = new FixedClock();
        $ledger = new Ledger(new PdoStore($pdo), $clock);
        $ledger->open(Account::asset('cash:psp', 'USD'));
        $ledger->open(Account::liability('user:42', 'USD'));
        $ledger->open(Account::liability('customer:acme:credits', 'USD'));
        $ledger->open(Account::revenue('revenue', 'USD'));
        $ledger->open(Account::revenue('revenue:breakage', 'USD'));

        $ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)]);
        $clock->advance('+1 day');
        $buy = $ledger->transfer('buy:1', 'user:42', 'revenue', 20_00, ['order' => 'A-1']);
        $clock->advance('+1 day');
        $ledger->reverse('buy:1:refund', $buy->id);
        $hold = $ledger->hold('job:7', 'user:42', 3_00);
        $ledger->capture('job:7:capture', $hold, 'revenue', 2_37);
        $ledger->release('job:7:release', $hold);
        $ledger->post('psp:re_1', [Leg::debit('user:42', 10_00), Leg::credit('cash:psp', 10_00)]);
        $ledger->credits()->grant('grant:1', 'customer:acme:credits', 50_00, 'cash:psp', 'revenue', 'revenue:breakage', $clock->now()->modify('+30 days'));
        self::assertTrue($ledger->verify()->ok());

        return $pdo;
    }
}
