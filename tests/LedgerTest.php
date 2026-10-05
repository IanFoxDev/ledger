<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Amount;
use IanFoxDev\Ledger\Exception\AccountConflict;
use IanFoxDev\Ledger\Exception\AlreadyReversed;
use IanFoxDev\Ledger\Exception\IdempotencyConflict;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Exception\NotReversible;
use IanFoxDev\Ledger\Exception\UnbalancedTransaction;
use IanFoxDev\Ledger\Exception\UnknownAccount;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Side;
use IanFoxDev\Ledger\Storage\InMemoryStore;
use IanFoxDev\Ledger\Storage\Store;
use IanFoxDev\Ledger\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * The same scenarios run against every store: subclasses in Storage/ swap in PostgreSQL
 * and MySQL.
 */
class LedgerTest extends TestCase
{
    protected Ledger $ledger;

    protected function store(): Store
    {
        return new InMemoryStore();
    }

    protected function setUp(): void
    {
        $this->ledger = new Ledger($this->store(), new FixedClock());
        $this->ledger->open(Account::asset('psp:stripe', 'USD'));
        $this->ledger->open(Account::liability('user:42', 'USD'));
        $this->ledger->open(Account::liability('user:7', 'USD'));
        $this->ledger->open(Account::revenue('revenue:fees', 'USD'));
    }

    final public function testDepositRaisesBothSidesOfTheBooks(): void
    {
        $tx = $this->deposit('dep:1', 'user:42', 10_00);

        self::assertSame('1000', (string) $this->ledger->balance('psp:stripe'));
        self::assertSame('1000', (string) $this->ledger->balance('user:42'));
        self::assertSame(1, $tx->id);
        self::assertCount(2, $tx->postings);
        self::assertSame(['order' => 'A-1'], $tx->meta);
    }

    final public function testTransferMovesMoneyBetweenAccountsOfTheSameNormalSide(): void
    {
        $this->deposit('dep:1', 'user:42', 10_00);

        $this->ledger->transfer('p2p:1', 'user:42', 'user:7', 3_50);
        $this->ledger->transfer('fee:1', 'user:42', 'revenue:fees', 50);

        self::assertSame('600', (string) $this->ledger->balance('user:42'));
        self::assertSame('350', (string) $this->ledger->balance('user:7'));
        self::assertSame('50', (string) $this->ledger->balance('revenue:fees'));
        self::assertSame('1000', (string) $this->ledger->balance('psp:stripe'));
    }

    final public function testTransferBetweenAnAssetAndALiabilityIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Post explicit debit and credit legs instead');

        $this->ledger->transfer('x', 'psp:stripe', 'user:42', 100);
    }

    final public function testUnbalancedTransactionWritesNothing(): void
    {
        try {
            $this->ledger->post('bad', [Leg::debit('psp:stripe', 100), Leg::credit('user:42', 99)]);
            self::fail('expected UnbalancedTransaction');
        } catch (UnbalancedTransaction $e) {
            self::assertSame('Debits and credits in USD differ by 1.', $e->getMessage());
        }
        self::assertTrue($this->ledger->balance('user:42')->isZero());
        self::assertNull($this->ledger->transaction(1));
    }

    final public function testEachCurrencyMustBalanceOnItsOwn(): void
    {
        $this->ledger->open(Account::asset('psp:eur', 'EUR'));
        $this->ledger->open(Account::liability('user:42:eur', 'EUR'));

        $this->expectException(UnbalancedTransaction::class);
        $this->expectExceptionMessage('Debits and credits in USD differ by 100.');

        // 100 USD in, 100 EUR out: the totals match, the currencies do not.
        $this->ledger->post('fx', [Leg::debit('psp:stripe', 100), Leg::credit('user:42:eur', 100)]);
    }

    final public function testAccountThatMustNotGoNegativeIsProtected(): void
    {
        $this->deposit('dep:1', 'user:42', 5_00);

        try {
            $this->ledger->transfer('p2p:1', 'user:42', 'user:7', 5_01);
            self::fail('expected InsufficientFunds');
        } catch (InsufficientFunds $e) {
            self::assertSame('Account "user:42" has 500 and cannot go to -1.', $e->getMessage());
        }
        self::assertSame('500', (string) $this->ledger->balance('user:42'));
        self::assertTrue($this->ledger->balance('user:7')->isZero());
    }

    final public function testAccountAllowedToGoNegative(): void
    {
        $this->ledger->open(Account::equity('world', 'USD', allowNegative: true));

        $this->ledger->transfer('mint', 'world', 'user:42', 2_00);

        self::assertSame('-200', (string) $this->ledger->balance('world'));
    }

    final public function testLegsThatRaiseABalanceComeFirst(): void
    {
        // user:42 is empty; the transaction credits it and then pays a fee from it.
        $tx = $this->ledger->post('dep+fee', [
            Leg::debit('user:42', 30),
            Leg::credit('revenue:fees', 30),
            Leg::debit('psp:stripe', 10_00),
            Leg::credit('user:42', 10_00),
        ]);

        $chain = $tx->postingsOf('user:42');
        self::assertSame([Side::Credit, Side::Debit], [$chain[0]->side, $chain[1]->side]);
        self::assertSame(['1000', '970'], [(string) $chain[0]->balanceAfter, (string) $chain[1]->balanceAfter]);
        self::assertSame([1, 2], [$chain[0]->sequence, $chain[1]->sequence]);
    }

    final public function testSameKeySameContentReturnsTheStoredTransaction(): void
    {
        $first = $this->deposit('dep:1', 'user:42', 10_00);
        $again = $this->ledger->post('dep:1', [Leg::credit('user:42', 10_00), Leg::debit('psp:stripe', 10_00)], ['order' => 'A-1'], 'deposit');

        self::assertSame($first->id, $again->id);
        self::assertSame('1000', (string) $this->ledger->balance('user:42'));
    }

    final public function testSameKeyDifferentContentThrows(): void
    {
        $this->deposit('dep:1', 'user:42', 10_00);

        $this->expectException(IdempotencyConflict::class);
        $this->expectExceptionMessage('Key "dep:1" was already used for a different transaction');

        $this->deposit('dep:1', 'user:42', 10_01);
    }

    final public function testUnknownAccount(): void
    {
        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('Account "user:404" is not open.');

        $this->ledger->post('x', [Leg::debit('psp:stripe', 1), Leg::credit('user:404', 1)]);
    }

    final public function testOpeningAnAccountAgain(): void
    {
        $same = $this->ledger->open(Account::liability('user:42', 'USD'));
        self::assertSame('user:42', $same->code);

        $this->expectException(AccountConflict::class);
        $this->expectExceptionMessage('Account "user:42" is already open as liability USD.');
        $this->ledger->open(Account::liability('user:42', 'EUR'));
    }

    final public function testAmountsBeyondSixtyFourBits(): void
    {
        $this->ledger->open(Account::asset('wallet:eth', 'ETH'));
        $this->ledger->open(Account::liability('user:42:eth', 'ETH'));
        $wei = '25000000000000000000000'; // 25,000 ETH in wei

        $this->ledger->post('eth:1', [Leg::debit('wallet:eth', $wei), Leg::credit('user:42:eth', $wei)]);
        $this->ledger->post('eth:2', [Leg::debit('wallet:eth', $wei), Leg::credit('user:42:eth', $wei)]);

        self::assertSame('50000000000000000000000', (string) $this->ledger->balance('user:42:eth'));
    }

    final public function testFloatsAndFractionsAreRejected(): void
    {
        foreach (['10.50', '1e3', ' 1', '+1', '01'] as $bad) {
            try {
                Amount::of($bad);
                self::fail("accepted $bad");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('floats are not allowed');
        $this->ledger->transfer('x', 'user:42', 'user:7', 1, ['rate' => 1.5]);
    }

    final public function testLegAmountMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Leg::debit('psp:stripe', 0);
    }

    final public function testReversalSwapsEverySideAndLinksTheOriginal(): void
    {
        $deposit = $this->deposit('dep:1', 'user:42', 10_00);

        $reversal = $this->ledger->reverse('dep:1:reverse', $deposit->id, ['reason' => 'chargeback']);

        self::assertSame($deposit->id, $reversal->reverses);
        self::assertSame('reversal', $reversal->type);
        self::assertSame(['reason' => 'chargeback'], $reversal->meta);
        self::assertTrue($this->ledger->balance('user:42')->isZero());
        self::assertTrue($this->ledger->balance('psp:stripe')->isZero());
        self::assertSame($reversal->id, $this->ledger->reversalOf($deposit->id)?->id);
        self::assertNull($this->ledger->reversalOf($reversal->id));
        $sides = array_map(static fn($p): string => $p->account . ' ' . $p->side->value, $reversal->postings);
        sort($sides);
        self::assertSame(['psp:stripe C', 'user:42 D'], $sides);
    }

    final public function testATransactionIsReversedOnce(): void
    {
        $deposit = $this->deposit('dep:1', 'user:42', 10_00);
        $first = $this->ledger->reverse('rev:1', $deposit->id);

        self::assertSame($first->id, $this->ledger->reverse('rev:1', $deposit->id)->id);

        $this->expectException(AlreadyReversed::class);
        $this->expectExceptionMessage(\sprintf('Transaction %d was already reversed by %d (key "rev:1").', $deposit->id, $first->id));
        $this->ledger->reverse('rev:2', $deposit->id);
    }

    final public function testAReversalIsNotReversed(): void
    {
        $deposit = $this->deposit('dep:1', 'user:42', 10_00);
        $reversal = $this->ledger->reverse('rev:1', $deposit->id);

        $this->expectException(NotReversible::class);
        $this->expectExceptionMessage('post the original again instead');
        $this->ledger->reverse('rev:rev:1', $reversal->id);
    }

    final public function testReversingAnUnknownTransaction(): void
    {
        $this->expectException(NotReversible::class);
        $this->ledger->reverse('rev:404', 404);
    }

    final public function testMoneyAlreadySpentCannotBeReversed(): void
    {
        $deposit = $this->deposit('dep:1', 'user:42', 10_00);
        $this->ledger->transfer('buy:1', 'user:42', 'revenue:fees', 6_00);

        try {
            $this->ledger->reverse('rev:1', $deposit->id);
            self::fail('expected InsufficientFunds');
        } catch (InsufficientFunds $e) {
            self::assertSame('Account "user:42" has 400 and cannot go to -600.', $e->getMessage());
        }
        self::assertNull($this->ledger->reversalOf($deposit->id));

        // Refund the purchase first, then the deposit can go.
        $this->ledger->reverse('rev:buy:1', 2);
        $this->ledger->reverse('rev:1', $deposit->id);
        self::assertTrue($this->ledger->balance('user:42')->isZero());
        self::assertTrue($this->ledger->balance('revenue:fees')->isZero());
    }

    final protected function deposit(string $key, string $user, int $cents): Transaction
    {
        return $this->ledger->post($key, [Leg::debit('psp:stripe', $cents), Leg::credit($user, $cents)], ['order' => 'A-1'], 'deposit');
    }
}
