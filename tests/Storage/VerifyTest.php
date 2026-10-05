<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Tests\FixedClock;
use IanFoxDev\Ledger\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rows changed by hand in the database, the way a "quick fix" in production would. The
 * check must name each one.
 */
final class VerifyTest extends TestCase
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
    public function testAnAmountChangedInPlace(string $env): void
    {
        [$pdo, $ledger] = $this->history($env);

        $pdo->exec("UPDATE ledger_postings SET amount = amount + 1 WHERE account = 'user:42' AND sequence = 1");

        self::assertSame([
            Violation::BALANCE_MISMATCH . ' user:42',
            Violation::UNBALANCED . ' 1',
            Violation::HASH_MISMATCH . ' 1',
        ], $this->kinds($ledger));
    }

    /**
     * Someone "corrects" a deposit from 50.00 to 60.00 and carefully fixes both legs and
     * every balance after it. The chains and the sums hold; the content hash does not.
     */
    #[DataProvider('databases')]
    public function testACarefulFixIsStillFound(string $env): void
    {
        [$pdo, $ledger] = $this->history($env);

        $pdo->exec("UPDATE ledger_postings SET amount = 6000 WHERE transaction_id = 1");
        $pdo->exec("UPDATE ledger_postings SET balance_after = balance_after + 1000 WHERE account = 'user:42'");
        $pdo->exec("UPDATE ledger_postings SET balance_after = balance_after - 1000 WHERE account = 'world'");

        self::assertSame([Violation::HASH_MISMATCH . ' 1'], $this->kinds($ledger));
        self::assertSame('Transaction 1 ("dep:1"): its postings or meta are not what was written.', $ledger->verify()->violations[0]->message);
    }

    #[DataProvider('databases')]
    public function testADeletedPosting(string $env): void
    {
        [$pdo, $ledger] = $this->history($env);

        $pdo->exec("DELETE FROM ledger_postings WHERE account = 'user:42' AND sequence = 2");

        self::assertSame([
            Violation::CHAIN_GAP . ' user:42',
            Violation::BALANCE_MISMATCH . ' user:42',
            Violation::UNBALANCED . ' 2',
            Violation::HASH_MISMATCH . ' 2',
            // The refund no longer mirrors the purchase it reverses.
            Violation::REVERSAL_MISMATCH . ' 3',
        ], $this->kinds($ledger));
    }

    #[DataProvider('databases')]
    public function testEditedMetaAndABrokenReversal(string $env): void
    {
        [$pdo, $ledger] = $this->history($env);

        $pdo->exec("UPDATE ledger_transactions SET meta = '{\"order\":\"B-2\"}' WHERE id = 2");
        $pdo->exec('UPDATE ledger_transactions SET reverses = 1 WHERE id = 3');

        self::assertSame([
            Violation::HASH_MISMATCH . ' 2',
            Violation::HASH_MISMATCH . ' 3',
            Violation::REVERSAL_MISMATCH . ' 3',
        ], $this->kinds($ledger));
    }

    /**
     * @return array{\PDO, Ledger}
     */
    private function history(string $env): array
    {
        $pdo = Databases::fresh($env);
        $ledger = new Ledger(new PdoStore($pdo), new FixedClock());
        $ledger->open(Account::equity('world', 'USD', allowNegative: true));
        $ledger->open(Account::liability('user:42', 'USD'));
        $ledger->open(Account::revenue('revenue', 'USD'));
        $ledger->transfer('dep:1', 'world', 'user:42', 50_00);
        $buy = $ledger->transfer('buy:1', 'user:42', 'revenue', 20_00, ['order' => 'A-1']);
        $ledger->reverse('buy:1:refund', $buy->id);
        self::assertTrue($ledger->verify()->ok());

        return [$pdo, $ledger];
    }

    /**
     * @return list<string>
     */
    private function kinds(Ledger $ledger): array
    {
        return array_map(static fn(Violation $v): string => $v->kind . ' ' . ($v->account ?? $v->transaction), $ledger->verify()->violations);
    }
}
