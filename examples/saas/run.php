<?php

declare(strict_types=1);

// A SaaS that runs AI jobs for its customers. Two ways to pay:
//
//   - a prepaid wallet in USD: a job is priced up front, the price is held, and only what
//     the job actually cost is captured;
//   - credits that expire: bought for 30 days, promotional ones for 7. Usage takes the
//     credits that expire first, and what expires unused is breakage.
//
//   php examples/saas/run.php            in memory
//   php examples/saas/run.php 'pgsql:host=127.0.0.1;dbname=app;user=app;password=app'
//                                        in a database with the tables from schema/

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Clock;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Storage\InMemoryStore;
use IanFoxDev\Ledger\Storage\PdoStore;

require __DIR__ . '/../../vendor/autoload.php';

// A clock the example moves forward, so the output is the same on every run.
$clock = new class implements Clock {
    public DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
};
$store = isset($argv[1]) ? new PdoStore(new PDO($argv[1])) : new InMemoryStore();
$ledger = new Ledger($store, $clock);
$usd = static fn($amount): string => number_format((int) (string) $amount / 100, 2, '.', ',');
$line = static function (string $what, string ...$values): void {
    echo str_pad($what, 46), implode('  ', $values), "\n";
};

// Chart of accounts. The customer's wallet and credits are liabilities: the platform owes
// them. Money at the payment provider is an asset.
$ledger->open(Account::asset('cash:psp', 'USD'));
$ledger->open(Account::liability('customer:acme:wallet', 'USD'));
$ledger->open(Account::liability('customer:acme:credits', 'USD'));
$ledger->open(Account::revenue('revenue:jobs', 'USD'));
$ledger->open(Account::revenue('revenue:breakage', 'USD'));
$ledger->open(Account::expense('expense:promotions', 'USD'));

echo "1. Wallet\n";
$ledger->post('psp:ch_1001', [Leg::debit('cash:psp', 100_00), Leg::credit('customer:acme:wallet', 100_00)], ['charge' => 'ch_1001'], 'deposit');
$line('deposit via the payment provider', $usd($ledger->balance('customer:acme:wallet')));

$hold = $ledger->hold('job:7:hold', 'customer:acme:wallet', 3_00, ['job' => 7]);
$line('job 7 priced at 3.00, held', 'balance ' . $usd($ledger->balance('customer:acme:wallet')), 'held ' . $usd($ledger->held('customer:acme:wallet')));
$ledger->capture('job:7:capture', $hold, 'revenue:jobs', 2_37);
$ledger->release('job:7:release', $hold);
$line('job 7 cost 2.37: captured, the rest released', 'balance ' . $usd($ledger->balance('customer:acme:wallet')), 'held ' . $usd($ledger->held('customer:acme:wallet')));

// A worker crashed after capturing and retried the whole job: nothing moves twice.
$ledger->capture('job:7:capture', $hold, 'revenue:jobs', 2_37);
$line('the capture retried with the same key', 'balance ' . $usd($ledger->balance('customer:acme:wallet')), 'revenue ' . $usd($ledger->balance('revenue:jobs')));

$charge = $ledger->post('psp:ch_1002', [Leg::debit('cash:psp', 20_00), Leg::credit('customer:acme:wallet', 20_00)], ['charge' => 'ch_1002'], 'deposit');
$ledger->reverse('psp:ch_1002:refund', $charge->id, ['reason' => 'duplicate charge']);
$line('a duplicate charge of 20.00, then refunded', 'balance ' . $usd($ledger->balance('customer:acme:wallet')), 'reversal of #' . $charge->id);

echo "\n2. Credits\n";
$credits = $ledger->credits();
$credits->grant('order:5001', 'customer:acme:credits', 50_00, from: 'cash:psp', revenue: 'revenue:jobs', breakage: 'revenue:breakage', expiresAt: $clock->now->modify('+30 days'));
$credits->grant('promo:welcome:acme', 'customer:acme:credits', 5_00, from: 'expense:promotions', revenue: 'revenue:jobs', breakage: 'expense:promotions', expiresAt: $clock->now->modify('+7 days'));
$line('bought 50.00 for 30 days, given 5.00 for 7', 'available ' . $usd($credits->available('customer:acme:credits')));

$clock->now = $clock->now->modify('+2 days');
$job = $credits->consume('job:8', 'customer:acme:credits', 7_40, ['job' => 8]);
$parts = [];
foreach ($job->postings as $posting) {
    if (str_contains($posting->account, '@lot:')) {
        $parts[] = $usd($posting->amount) . ' from the lot ' . substr($posting->account, strpos($posting->account, '@lot:') + 5, 8);
    }
}
$line('job 8 used 7.40', implode(', ', $parts));

$clock->now = $clock->now->modify('+29 days');
try {
    $credits->consume('job:9', 'customer:acme:credits', 1_00);
} catch (InsufficientFunds $e) {
    $line('day 31: job 9 refused', $e->getMessage());
}
foreach ($credits->expire('customer:acme:credits') as $expired) {
    $line('expired: lot of transaction #' . $expired->meta['lot'], $usd($expired->postings[0]->amount) . ' to breakage');
}

echo "\n3. The books\n";
foreach (['cash:psp', 'customer:acme:wallet', 'revenue:jobs', 'revenue:breakage', 'expense:promotions'] as $account) {
    $line($account, str_pad($usd($ledger->balance($account)), 8, ' ', STR_PAD_LEFT));
}
$check = $ledger->verify();
$line('verify', sprintf('%d accounts, %d transactions, %d postings: %s', $check->accounts, $check->transactions, $check->postings, $check->ok() ? 'ok' : 'VIOLATIONS'));
