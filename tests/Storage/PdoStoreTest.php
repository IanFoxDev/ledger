<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Exception\AlreadyReversed;
use IanFoxDev\Ledger\Exception\IdempotencyConflict;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Tests\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoStoreTest extends TestCase
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
    public function testWritesInsideTheApplicationTransaction(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = $this->ledger($pdo);
        $pdo->exec('DROP TABLE IF EXISTS orders');
        $pdo->exec('CREATE TABLE orders (id varchar(20) PRIMARY KEY)');

        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO orders VALUES ('A-1')");
        $ledger->transfer('order:A-1', 'user:42', 'revenue', 3_00);
        $pdo->rollBack();

        self::assertSame('1000', (string) $ledger->balance('user:42'));
        self::assertSame(0, $this->rowsIn($pdo, 'orders'));
        self::assertSame(1, $this->rowsIn($pdo, 'ledger_transactions'));

        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO orders VALUES ('A-1')");
        $ledger->transfer('order:A-1', 'user:42', 'revenue', 3_00);
        $pdo->commit();

        self::assertSame('700', (string) $ledger->balance('user:42'));
        self::assertSame(1, $this->rowsIn($pdo, 'orders'));
    }

    #[DataProvider('databases')]
    public function testReadsBackWhatItWrote(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = $this->ledger($pdo);

        $written = $ledger->post('fee:9', [
            Leg::debit('user:42', 1_25),
            Leg::credit('revenue', 1_00),
            Leg::credit('user:7', 25),
        ], ['order' => 'A-9', 'attempt' => 2, 'manual' => false, 'note' => null, 'city' => 'Limassol/CY'], 'fee');

        $read = $ledger->transaction($written->id);
        self::assertEquals($written, $read);
        self::assertSame('2026-10-05 12:00:00.000000', $read?->createdAt->format('Y-m-d H:i:s.u'));
    }

    /**
     * The caller's transaction read the key table before calling the ledger, so in MySQL
     * (REPEATABLE READ) its snapshot does not have the key another process committed since.
     * The retry must still return that transaction, not post a second one or fail.
     */
    #[DataProvider('databases')]
    public function testRetryFromAnOldSnapshotReturnsTheCommittedTransaction(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = $this->ledger($pdo);
        $other = new Ledger(new PdoStore(Databases::connect($env)), new FixedClock());

        $pdo->beginTransaction();
        $this->rowsIn($pdo, 'ledger_transactions');
        $first = $other->transfer('pay:1', 'user:42', 'revenue', 2_00);
        $retry = $ledger->transfer('pay:1', 'user:42', 'revenue', 2_00);
        $pdo->commit();

        self::assertSame($first->id, $retry->id);
        self::assertSame('800', (string) $ledger->balance('user:42'));

        $pdo->beginTransaction();
        $this->rowsIn($pdo, 'ledger_transactions');
        $other->transfer('pay:2', 'user:42', 'revenue', 2_00);
        try {
            $ledger->transfer('pay:2', 'user:42', 'user:7', 2_00);
            self::fail('expected IdempotencyConflict');
        } catch (IdempotencyConflict $e) {
            self::assertStringContainsString('Key "pay:2" was already used for a different transaction (content ', $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * The first attempt spent the whole balance. A retry from an old snapshot misses the
     * key, sees a balance of zero and would report InsufficientFunds; it must return the
     * stored transaction instead.
     */
    #[DataProvider('databases')]
    public function testRetryOfAPaymentThatEmptiedTheAccount(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = $this->ledger($pdo);
        $other = new Ledger(new PdoStore(Databases::connect($env)), new FixedClock());

        $pdo->beginTransaction();
        $this->rowsIn($pdo, 'ledger_transactions');
        $first = $other->transfer('pay:all', 'user:42', 'revenue', 10_00);
        $retry = $ledger->transfer('pay:all', 'user:42', 'revenue', 10_00);
        $pdo->commit();

        self::assertSame($first->id, $retry->id);
        self::assertTrue($ledger->balance('user:42')->isZero());
    }

    /**
     * Two reversals of the same transaction under different keys. From an old snapshot the
     * second does not see the first, and the unique index on reverses refuses it.
     */
    #[DataProvider('databases')]
    public function testSecondReversalFromAnOldSnapshotIsRefused(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = $this->ledger($pdo);
        $other = new Ledger(new PdoStore(Databases::connect($env)), new FixedClock());
        $payment = $ledger->transfer('pay:1', 'user:42', 'revenue', 2_00);

        $pdo->beginTransaction();
        $this->rowsIn($pdo, 'ledger_transactions');
        $other->reverse('refund:1', $payment->id);
        try {
            $ledger->reverse('refund:1:again', $payment->id);
            self::fail('expected AlreadyReversed');
        } catch (AlreadyReversed) {
        } finally {
            $pdo->rollBack();
        }
        self::assertSame('1000', (string) $ledger->balance('user:42'));
    }

    #[DataProvider('databases')]
    public function testTheChainCannotFork(string $env): void
    {
        $pdo = Databases::fresh($env);
        $ledger = $this->ledger($pdo);
        $tx = $ledger->transfer('pay:1', 'user:42', 'revenue', 1_00);

        // A writer that skipped the lock and computed the same next sequence number.
        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO ledger_postings (account, sequence, transaction_id, position, side, amount, balance_after) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['user:42', 2, $tx->id, 9, 'D', '100', '800']);
    }

    #[DataProvider('databases')]
    public function testAccountCodesAreCaseSensitive(string $env): void
    {
        $ledger = $this->ledger(Databases::fresh($env));

        $ledger->open(Account::liability('USER:42', 'EUR'));

        self::assertSame('EUR', $ledger->open(Account::liability('USER:42', 'EUR'))->currency);
        self::assertSame('USD', $ledger->open(Account::liability('user:42', 'USD'))->currency);
    }

    private function ledger(\PDO $pdo): Ledger
    {
        $ledger = new Ledger(new PdoStore($pdo), new FixedClock());
        $ledger->open(Account::equity('world', 'USD', allowNegative: true));
        $ledger->open(Account::liability('user:42', 'USD'));
        $ledger->open(Account::liability('user:7', 'USD'));
        $ledger->open(Account::revenue('revenue', 'USD'));
        $ledger->transfer('seed', 'world', 'user:42', 10_00);

        return $ledger;
    }

    private function rowsIn(\PDO $pdo, string $table): int
    {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM $table");
        $statement->execute();
        $count = $statement->fetchColumn();

        return \is_numeric($count) ? (int) $count : -1;
    }
}
