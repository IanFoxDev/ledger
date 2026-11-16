<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Outbox;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Outbox\OutboxListener;
use IanFoxDev\Ledger\Posting;
use IanFoxDev\Ledger\Storage\InMemoryStore;
use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Tests\FixedClock;
use IanFoxDev\Ledger\Tests\Storage\Databases;
use IanFoxDev\Ledger\Transaction;
use IanFoxDev\Ledger\TransactionListener;
use IanFoxDev\Outbox\Connection\PdoConnection;
use IanFoxDev\Outbox\Dialect;
use IanFoxDev\Outbox\Outbox;
use IanFoxDev\Outbox\Schema;
use IanFoxDev\Outbox\Testing\InMemoryRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboxListenerTest extends TestCase
{
    public function testOneEventPerPostingKeyedByAccount(): void
    {
        $recorder = new InMemoryRecorder();
        $ledger = $this->ledger(new InMemoryStore(), new OutboxListener($recorder));

        $ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)], ['provider' => 'psp']);

        $messages = $recorder->ofType('ledger.account.posted');
        self::assertSame(['cash:psp', 'user:42'], array_map(static fn($m): string => $m->aggregateId, $messages));
        self::assertSame('ledger.account', $messages[1]->aggregateType);
        self::assertSame(
            '{"account":"user:42","sequence":1,"side":"C","amount":"5000","balance_after":"5000","transaction":'
            . '{"id":1,"key":"psp:ch_1001","type":"transaction","meta":{"provider":"psp"},"reverses":null,"created_at":"2026-10-05T12:00:00.000000Z"}}',
            $messages[1]->payload,
        );
    }

    /**
     * A retried request returns the stored transaction and must not announce it again;
     * a refused one announces nothing.
     */
    public function testARetryAndARefusalRecordNothing(): void
    {
        $recorder = new InMemoryRecorder();
        $ledger = $this->ledger(new InMemoryStore(), new OutboxListener($recorder));
        $ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)]);
        $recorder->clear();

        $ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)]);
        try {
            $ledger->transfer('buy:1', 'user:42', 'revenue', 60_00);
            self::fail('expected InsufficientFunds');
        } catch (InsufficientFunds) {
        }

        self::assertSame([], $recorder->messages());
    }

    public function testAFilterSkipsHoldAccountsAndAnEmptyMetaIsAnObject(): void
    {
        $recorder = new InMemoryRecorder();
        $only = static fn(Posting $posting, Transaction $transaction): bool => !str_contains($posting->account, '@hold:');
        $ledger = $this->ledger(new InMemoryStore(), new OutboxListener($recorder, 'wallet', $only));
        $ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)]);
        $recorder->clear();

        $ledger->hold('job:7', 'user:42', 3_00);

        $messages = $recorder->messages();
        self::assertCount(1, $messages);
        self::assertSame(['wallet', 'user:42'], [$messages[0]->aggregateType, $messages[0]->aggregateId]);
        self::assertStringContainsString('"type":"hold","meta":{},', $messages[0]->payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'postgresql' => ['LEDGER_PG_DSN'];
        yield 'mysql' => ['LEDGER_MYSQL_DSN'];
    }

    /**
     * The point of the adapter: the events and the postings are one commit. The order is
     * saved together with the money, and when the transaction rolls back, nothing is left
     * for the relay to publish.
     */
    #[DataProvider('databases')]
    public function testEventsCommitAndRollBackWithThePostings(string $env): void
    {
        $pdo = Databases::fresh($env);
        $pdo->exec('DROP TABLE IF EXISTS outbox');
        foreach (Schema::statements($env === 'LEDGER_PG_DSN' ? Dialect::PostgreSQL : Dialect::MySQL) as $statement) {
            $pdo->exec($statement);
        }
        $ledger = $this->ledger(new PdoStore($pdo), new OutboxListener(new Outbox(new PdoConnection($pdo), '/billing')));
        $rows = static fn(): int => \count(self::accounts($pdo));

        // The ledger's own transaction.
        $ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)]);
        self::assertSame(2, $rows());

        // The application's transaction, rolled back after the ledger wrote.
        $pdo->beginTransaction();
        $ledger->transfer('buy:1', 'user:42', 'revenue', 20_00);
        self::assertSame(4, $rows());
        $pdo->rollBack();
        self::assertSame(2, $rows());
        self::assertSame('5000', (string) $ledger->balance('user:42'));

        $pdo->beginTransaction();
        $ledger->transfer('buy:1', 'user:42', 'revenue', 20_00);
        $pdo->commit();
        self::assertSame(['cash:psp', 'user:42', 'revenue', 'user:42'], self::accounts($pdo));
    }

    public function testAListenerThatThrowsUndoesTheWrite(): void
    {
        $failing = new class implements TransactionListener {
            public function posted(Transaction $transaction): void
            {
                throw new \RuntimeException('the outbox table is missing');
            }
        };
        $store = new InMemoryStore();
        $ledger = $this->ledger($store);
        $broken = new Ledger($store, new FixedClock(), $failing);

        try {
            $broken->post('psp:ch_1001', [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)]);
            self::fail('expected the listener to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('the outbox table is missing', $e->getMessage());
        }

        self::assertSame('0', (string) $ledger->balance('user:42'));
        self::assertSame(0, $ledger->verify()->transactions);
    }

    /**
     * @return list<mixed> the aggregate ids in the outbox, oldest first
     */
    private static function accounts(\PDO $pdo): array
    {
        $statement = $pdo->query('SELECT aggregate_id FROM outbox ORDER BY id');
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return array_values($statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function ledger(\IanFoxDev\Ledger\Storage\Store $store, TransactionListener ...$listeners): Ledger
    {
        $ledger = new Ledger($store, new FixedClock(), ...$listeners);
        $ledger->open(Account::asset('cash:psp', 'USD'));
        $ledger->open(Account::liability('user:42', 'USD'));
        $ledger->open(Account::revenue('revenue', 'USD'));

        return $ledger;
    }
}
