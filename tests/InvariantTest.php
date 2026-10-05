<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests;

use Brick\Math\BigInteger;
use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\AccountType;
use IanFoxDev\Ledger\Amount;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Exception\UnbalancedTransaction;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Side;
use IanFoxDev\Ledger\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Random transactions against random accounts. After every one, whatever was accepted or
 * refused, the books must still balance and every balance must match its postings.
 */
final class InvariantTest extends TestCase
{
    private const int SEED = 20261005;
    private const int TRANSACTIONS = 3000;

    public function testRandomTransactionsKeepTheBooksBalanced(): void
    {
        mt_srand(self::SEED);
        $store = new InMemoryStore();
        $ledger = new Ledger($store, new FixedClock());
        $types = AccountType::cases();
        $codes = [];
        foreach (['USD', 'ETH'] as $currency) {
            for ($i = 0; $i < 8; $i++) {
                $account = new Account("$currency:$i", $types[mt_rand(0, 4)], $currency, mt_rand(0, 3) === 0);
                $ledger->open($account);
                $codes[$currency][] = $account->code;
            }
        }

        $accepted = 0;
        $refused = 0;
        for ($n = 0; $n < self::TRANSACTIONS; $n++) {
            $currency = mt_rand(0, 1) === 0 ? 'USD' : 'ETH';
            $legs = $this->randomLegs($codes[$currency], $currency === 'ETH');
            $broken = mt_rand(0, 19) === 0;
            if ($broken) {
                $legs[] = Leg::debit($codes[$currency][0], 1);
            }
            try {
                $ledger->post("tx:$n", $legs);
                self::assertFalse($broken, 'an unbalanced transaction was accepted');
                $accepted++;
            } catch (InsufficientFunds) {
                $refused++;
            } catch (UnbalancedTransaction) {
                self::assertTrue($broken);
                $refused++;
            }
        }

        self::assertGreaterThan(1000, $accepted);
        self::assertGreaterThan(100, $refused);
        $this->assertBooks($store, $ledger);
    }

    public function testRetriesWithTheSameKeyDoNotMoveMoneyTwice(): void
    {
        mt_srand(self::SEED + 1);
        $store = new InMemoryStore();
        $ledger = new Ledger($store, new FixedClock());
        $ledger->open(Account::equity('world', 'USD', allowNegative: true));
        for ($i = 0; $i < 5; $i++) {
            $ledger->open(Account::liability("user:$i", 'USD'));
        }

        $expected = array_fill(0, 5, 0);
        for ($n = 0; $n < 500; $n++) {
            $user = mt_rand(0, 4);
            $amount = mt_rand(1, 10_000);
            $retries = mt_rand(1, 3);
            for ($r = 0; $r < $retries; $r++) {
                $ledger->transfer("dep:$n", 'world', "user:$user", $amount);
            }
            $expected[$user] += $amount;
        }

        foreach ($expected as $user => $sum) {
            self::assertSame((string) $sum, (string) $ledger->balance("user:$user"));
        }
        $this->assertBooks($store, $ledger);
    }

    /**
     * @param list<string> $codes
     * @return list<Leg>
     */
    private function randomLegs(array $codes, bool $huge): array
    {
        $legs = [];
        $debits = BigInteger::zero();
        $count = mt_rand(1, 3);
        for ($i = 0; $i < $count; $i++) {
            $amount = $this->randomAmount($huge);
            $debits = $debits->plus($amount);
            $legs[] = Leg::debit($codes[mt_rand(0, \count($codes) - 1)], Amount::of($amount));
        }
        // Split the same total over one to three credits.
        $credits = mt_rand(1, 3);
        $left = $debits;
        for ($i = 1; $i < $credits && $left->isGreaterThan(1); $i++) {
            $part = $left->quotient(2);
            $legs[] = Leg::credit($codes[mt_rand(0, \count($codes) - 1)], Amount::of($part));
            $left = $left->minus($part);
        }
        $legs[] = Leg::credit($codes[mt_rand(0, \count($codes) - 1)], Amount::of($left));
        shuffle($legs);

        return $legs;
    }

    private function randomAmount(bool $huge): BigInteger
    {
        if (!$huge) {
            return BigInteger::of(mt_rand(1, 100_000));
        }

        // Up to 10^27: a billion ETH in wei, well past 64 bits.
        return BigInteger::of(mt_rand(1, 999_999_999))->multipliedBy(BigInteger::of(10)->power(mt_rand(0, 18)));
    }

    private function assertBooks(InMemoryStore $store, Ledger $ledger): void
    {
        $net = [];
        foreach ($store->accounts() as $account) {
            $balance = Amount::zero();
            $sequence = 0;
            foreach ($store->postingsOf($account->code) as $posting) {
                $sequence++;
                self::assertSame($sequence, $posting->sequence, "gap in the chain of $account->code");
                $delta = $account->direction($posting->side) > 0 ? $posting->amount : $posting->amount->negated();
                $balance = $balance->plus($delta);
                self::assertTrue($balance->equals($posting->balanceAfter), "balance_after of $account->code #$sequence");
                if (!$account->allowNegative) {
                    self::assertFalse($balance->isNegative(), "$account->code went negative");
                }
                $signed = $posting->side === Side::Debit ? $posting->amount : $posting->amount->negated();
                $net[$account->currency] = ($net[$account->currency] ?? Amount::zero())->plus($signed);
            }
            self::assertTrue($balance->equals($ledger->balance($account->code)));
        }
        foreach ($net as $currency => $difference) {
            self::assertTrue($difference->isZero(), "debits and credits in $currency differ by $difference");
        }
    }
}
