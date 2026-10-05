<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Credits;
use IanFoxDev\Ledger\Exception\IdempotencyConflict;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Exception\NotReversible;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\InMemoryStore;
use IanFoxDev\Ledger\Storage\Store;
use PHPUnit\Framework\TestCase;

/**
 * A SaaS sells prepaid credits for AI jobs and gives promotional ones. Subclasses in
 * Storage/ run the same scenarios on PostgreSQL and MySQL.
 */
class CreditsTest extends TestCase
{
    protected Ledger $ledger;
    protected Credits $credits;
    protected FixedClock $clock;

    protected function store(): Store
    {
        return new InMemoryStore();
    }

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->ledger = new Ledger($this->store(), $this->clock);
        $this->credits = $this->ledger->credits();
        $this->ledger->open(Account::asset('cash:stripe', 'USD'));
        $this->ledger->open(Account::expense('expense:promo', 'USD'));
        $this->ledger->open(Account::liability('customer:7:credits', 'USD'));
        $this->ledger->open(Account::revenue('revenue:usage', 'USD'));
        $this->ledger->open(Account::revenue('revenue:breakage', 'USD'));
    }

    final public function testUsageTakesTheLotThatExpiresFirstAndTheRestExpires(): void
    {
        $paid = $this->buy('order:1', 50_00, '+30 days');
        $promo = $this->credits->grant('promo:welcome:7', 'customer:7:credits', 10_00, from: 'expense:promo', revenue: 'revenue:usage', breakage: 'expense:promo', expiresAt: $this->clock->now()->modify('+7 days'));
        self::assertSame('6000', (string) $this->credits->available('customer:7:credits'));

        $job = $this->credits->consume('job:1', 'customer:7:credits', 12_00, ['job' => 'job:1']);

        $taken = [];
        foreach ($job->postings as $posting) {
            $taken[$posting->account] = $posting->side->value . ' ' . $posting->amount;
        }
        self::assertSame([
            $promo->lotAccount => 'D 1000',
            $paid->lotAccount => 'D 200',
            'revenue:usage' => 'C 1200',
        ], $taken);
        self::assertSame('4800', (string) $this->credits->available('customer:7:credits'));

        $this->clock->advance('+31 days');
        self::assertTrue($this->credits->available('customer:7:credits')->isZero());
        $expired = $this->credits->expire('customer:7:credits');

        self::assertCount(1, $expired);
        self::assertSame(['credits' => 'customer:7:credits', 'lot' => $paid->id], $expired[0]->meta);
        self::assertSame('4800', (string) $this->ledger->balance('revenue:breakage'));
        self::assertSame('1200', (string) $this->ledger->balance('revenue:usage'));
        self::assertSame('5000', (string) $this->ledger->balance('cash:stripe'));
        self::assertSame('1000', (string) $this->ledger->balance('expense:promo'));
        self::assertSame([], $this->credits->expire('customer:7:credits'));
    }

    final public function testUnusedPromotionalCreditsGoBackToTheExpense(): void
    {
        $this->credits->grant('promo:1', 'customer:7:credits', 10_00, from: 'expense:promo', revenue: 'revenue:usage', breakage: 'expense:promo', expiresAt: $this->clock->now()->modify('+7 days'));
        $this->credits->consume('job:1', 'customer:7:credits', 3_00);

        $this->credits->expire('customer:7:credits', $this->clock->now()->modify('+7 days'));

        self::assertSame('300', (string) $this->ledger->balance('expense:promo'));
        self::assertSame('300', (string) $this->ledger->balance('revenue:usage'));
    }

    final public function testAnExpiredLotIsNotUsedEvenBeforeExpireRuns(): void
    {
        $this->buy('order:1', 5_00, '+1 day');
        $this->buy('order:2', 5_00, null);
        $this->clock->advance('+2 days');

        try {
            $this->credits->consume('job:1', 'customer:7:credits', 6_00);
            self::fail('expected InsufficientFunds');
        } catch (InsufficientFunds $e) {
            self::assertSame('Account "customer:7:credits" has 500 credits that have not expired; 600 requested.', $e->getMessage());
        }
        $job = $this->credits->consume('job:2', 'customer:7:credits', 5_00);
        self::assertSame('500', (string) $job->postings[0]->amount);
        self::assertSame('500', (string) $this->ledger->balance('revenue:usage'));
    }

    final public function testRetriesReturnTheFirstResult(): void
    {
        $lot = $this->buy('order:1', 20_00, '+30 days');
        self::assertSame($lot->id, $this->buy('order:1', 20_00, '+30 days')->id);

        $first = $this->credits->consume('job:1', 'customer:7:credits', 15_00);
        // Only 5.00 is left, but a retry must not plan again: it returns the first result.
        self::assertSame($first->id, $this->credits->consume('job:1', 'customer:7:credits', 15_00)->id);
        self::assertSame('500', (string) $this->credits->available('customer:7:credits'));

        $this->expectException(IdempotencyConflict::class);
        $this->credits->consume('job:1', 'customer:7:credits', 14_00);
    }

    final public function testLotsAreListedInTheOrderTheyAreUsed(): void
    {
        $never = $this->buy('order:1', 1_00, null);
        $late = $this->buy('order:2', 2_00, '+60 days');
        $soon = $this->buy('order:3', 3_00, '+10 days');
        $this->credits->consume('job:1', 'customer:7:credits', 1_00);

        $lots = $this->credits->lots('customer:7:credits');

        self::assertSame([$soon->id, $late->id, $never->id], array_map(static fn($l): int => $l->id, $lots));
        self::assertSame('300', (string) $lots[0]->granted);
        self::assertSame('200', (string) $lots[0]->remaining);
        self::assertEquals($this->clock->now()->modify('+10 days'), $lots[0]->expiresAt);
        self::assertNull($lots[2]->expiresAt);
        self::assertSame('revenue:breakage', $lots[2]->breakage);
    }

    final public function testCreditsAreALiability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cash:stripe" must be a liability, not asset');
        $this->credits->grant('x', 'cash:stripe', 1, from: 'expense:promo', revenue: 'revenue:usage', breakage: 'revenue:breakage');
    }

    final public function testCreditTransactionsAreNotReversed(): void
    {
        $lot = $this->buy('order:1', 1_00, null);

        $this->expectException(NotReversible::class);
        $this->ledger->reverse('order:1:refund', $lot->id);
    }

    private function buy(string $order, int $cents, ?string $expires): \IanFoxDev\Ledger\Lot
    {
        return $this->credits->grant(
            $order,
            'customer:7:credits',
            $cents,
            from: 'cash:stripe',
            revenue: 'revenue:usage',
            breakage: 'revenue:breakage',
            expiresAt: $expires === null ? null : $this->clock->now()->modify($expires),
            meta: ['order' => $order],
        );
    }
}
