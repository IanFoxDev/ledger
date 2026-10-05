# ledger

[![php](https://github.com/IanFoxDev/ledger/actions/workflows/php.yml/badge.svg)](https://github.com/IanFoxDev/ledger/actions/workflows/php.yml)

Double-entry ledger for PHP that runs inside your application's database transaction:
wallets, holds, refunds and prepaid credits that expire. Balances are derived from
postings, nothing is updated or deleted, and `verify()` recalculates everything and
names what does not add up.

> Status: v0.1. Until 1.0 a minor version may change the API; such changes are marked
> **BREAKING** in the [CHANGELOG](CHANGELOG.md). Stored transactions keep verifying: a
> change to what their hash covers would be breaking.

The usual wallet is a `balance` column and `UPDATE accounts SET balance = balance + ?`.
Two requests read the same balance and both spend it. A wrong row gets fixed in place, and
the history that would explain the balance is gone. A deposit is added to the user and
taken from nowhere, so the books do not balance and nothing notices. Reconciliation finds
it a month later.

## Install

```bash
composer require ianfoxdev/ledger
```

PHP 8.3 or later, PostgreSQL or MySQL 8. Create the tables from
[schema/postgresql.sql](schema/postgresql.sql) or [schema/mysql.sql](schema/mysql.sql).
The only dependency is `brick/math`.

## Example

```php
use IanFoxDev\Ledger\{Account, Ledger, Leg};
use IanFoxDev\Ledger\Storage\PdoStore;

$ledger = new Ledger(new PdoStore($pdo));                 // your application's connection

$ledger->open(Account::asset('cash:psp', 'USD'));
$ledger->open(Account::liability('user:42', 'USD'));      // the platform owes the user
$ledger->open(Account::revenue('revenue:fees', 'USD'));

// A deposit: money arrives at the payment provider and is owed to the user.
$ledger->post('psp:ch_1001', [
    Leg::debit('cash:psp', 50_00),                         // 50.00 USD in cents
    Leg::credit('user:42', 50_00),
]);

// A purchase: from the user's balance to revenue.
$ledger->transfer('order:A-17', 'user:42', 'revenue:fees', 12_50, ['order' => 'A-17']);

// An amount set aside while a job runs; only what it cost is taken.
$hold = $ledger->hold('job:7', 'user:42', 3_00);
$ledger->capture('job:7:capture', $hold, 'revenue:fees', 2_37);
$ledger->release('job:7:release', $hold);

$ledger->balance('user:42');                               // Amount 3513
$ledger->verify()->ok();                                   // true
```

The first argument of every write is an idempotency key. The same key with the same
content returns the stored transaction; with different content it throws:

```
Key "order:A-17" was already used for a different transaction (content 289203eac8af);
this call has 89472635a525.
```

[examples/saas](examples/saas) runs a wallet and expiring credits end to end, including
a retried capture, a refunded duplicate charge and credits that expire.

## What it guarantees

- **The books balance.** A transaction is a set of debit and credit legs; per currency,
  debits equal credits, or it throws before anything is written.
- **A balance is derived.** Every posting stores the account's sequence number and the
  balance after it. There is no balance column to update. The primary key (account,
  sequence) makes a write that skipped the lock fail instead of forking the chain.
- **Nothing is edited.** Rows are only inserted. `reverse()` undoes a transaction with a
  new one that swaps every side and links to the original; a transaction is reversed at
  most once.
- **No overdrafts by race.** A transaction locks its accounts with `SELECT ... FOR UPDATE`
  in the order of account code, so two writers cannot deadlock on each other, and checks
  accounts that must not go negative under the lock.
- **No floats, no overflow.** Amounts are integers in minor units, as arbitrary-precision
  integers stored in `NUMERIC(38,0)`. `"10.50"` is rejected; 25,000 ETH in wei works.
- **A retry does not move money twice.** Keys are unique. A retry that finds its key taken
  returns what was written. In MySQL that holds even when your transaction reads from an
  older snapshot (REPEATABLE READ); the ledger rereads with a locking read before it
  reports a failure.
- **Changes made by hand are found.** `verify()` recalculates every chain and checks every
  transaction's content against a SHA-256 taken when it was written. A deposit "fixed"
  from 50.00 to 60.00 directly in the database, with both legs and every later balance
  adjusted to match, is still reported.

Why it is built this way:
[docs/adr/0001-balances-are-derived-and-nothing-is-edited.md](docs/adr/0001-balances-are-derived-and-nothing-is-edited.md).

## Holds

`hold()` moves the amount to an account of its own (`user:42@hold:...`); `capture()` takes
part or all of it to an account with the same normal side, `release()` returns the rest.
What remains on a hold is that account's balance, read under the lock, so a second capture
of a settled hold fails even if it runs twice at once. `held('user:42')` is the total on
open holds.

## Credits that expire

```php
$credits = $ledger->credits();

$credits->grant('order:5001', 'customer:acme:credits', 50_00,
    from: 'cash:psp', revenue: 'revenue:jobs', breakage: 'revenue:breakage',
    expiresAt: new DateTimeImmutable('+30 days'));
$credits->grant('promo:welcome:acme', 'customer:acme:credits', 5_00,
    from: 'expense:promotions', revenue: 'revenue:jobs', breakage: 'expense:promotions',
    expiresAt: new DateTimeImmutable('+7 days'));

$credits->consume('job:8', 'customer:acme:credits', 7_40);   // 5.00 promo, then 2.40 paid
$credits->available('customer:acme:credits');                // 47.60, until the lot expires
$credits->expire('customer:acme:credits');                   // after 30 days: 47.60 to breakage
```

Each grant is a lot on an account of its own, named after its expiry, so usage takes the
lot that expires first. The customer's credits account is a liability: the platform owes
the service. Used credits go to the lot's revenue account; what expires unused goes to its
breakage account. For promotional credits, breakage can be the promotion expense itself,
which undoes the part of the expense that was never used. An expired lot is never used,
even before `expire()` has run.

## Isolation and throughput

Run writes at READ COMMITTED. A transaction the ledger opens itself does that. When it
joins yours, your level applies:

- **PostgreSQL**: READ COMMITTED is the default. Under REPEATABLE READ the end of an
  account's chain is read from your snapshot; if another transaction wrote to the account
  since, the posting is refused on its primary key and you get `ConcurrentWrite`, and a
  retry of a key committed after your snapshot gets `IdempotencyConflict`, because your
  snapshot cannot see it. Roll back and retry.
- **MySQL**: under the default REPEATABLE READ the writes stay correct, but two of them on
  accounts without postings yet can deadlock (error 1213) and must be retried.
  `SET SESSION transaction_isolation = 'READ-COMMITTED'` avoids it.

Every write to an account waits for the previous one. A revenue account that every
purchase credits is locked by every purchase. On a laptop (M1 Pro, the database in Docker,
each transfer its own transaction, 8 processes), `bench/transfers.php` gives:

| | 1000 accounts | 2 accounts |
|---|---|---|
| PostgreSQL 17 | 440 to 520 transfers/s | 150 to 160 transfers/s |
| MySQL 8.4 (Percona) | 560 to 690 transfers/s | 235 to 255 transfers/s |

These are not server numbers. The ratio is the point: spread writes scale, one hot account
does not.

## Checking the ledger

```bash
vendor/bin/ledger verify --dsn='pgsql:host=db;dbname=app' --user=app   # password from LEDGER_PASSWORD
```

```
9 accounts, 10 transactions, 21 postings
ok
```

Exit code 0 when nothing is wrong, 1 with violations (each one names the account or
transaction), 2 on a usage or connection error. `--json` for a machine-readable report.
It reads the whole ledger: run it from a nightly job, not a request.

## How it differs

- **bavix/laravel-wallet** keeps the balance in a `balance` column of its `wallets` table
  and offers `depositFloat(1.37)`. A deposit is a row on one wallet, with no account it
  came from, so there is no second side that has to agree.
- **climactic/laravel-credits** is a one-sided log with a running balance and float
  amounts (`creditAdd(100.00)`); credits do not expire.
- **Formance, Blnk, TigerBeetle** are ledgers as separate services. They do more, and your
  business row and the ledger entry cannot commit in one database transaction.

This library is for the case in between: the ledger lives in your database, writes in your
transaction, and can prove its balances.

More: [docs/usage.md](docs/usage.md).

## Not yet

Laravel and Symfony integration, multi-currency transactions with a rate, refunds of used
credits, expiry across all customers in one call, snapshots for very long chains, export to
[recon](https://github.com/IanFoxDev/recon), writing an outbox event in the same
transaction. Open an issue if you need one of them first.

## License

[MIT](LICENSE)
