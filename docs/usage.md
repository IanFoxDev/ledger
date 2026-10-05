# Usage

## Setting up

```php
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Storage\PdoStore;

$ledger = new Ledger(new PdoStore($pdo));
```

`$pdo` is the connection your application already uses. Create the tables from
`schema/postgresql.sql` or `schema/mysql.sql` with your migrations. The table names are
fixed: `ledger_accounts`, `ledger_transactions`, `ledger_postings`.

A second argument takes a `Clock` (`now(): DateTimeImmutable`). The default is the system
clock in UTC; tests pass a fixed one.

## Accounts

```php
$ledger->open(Account::liability('user:42', 'USD'));
$ledger->open(Account::equity('opening-balances', 'USD', allowNegative: true));
```

| Type | Normal side | Typical accounts |
|---|---|---|
| asset | debit | money at a payment provider or bank, receivables |
| liability | credit | user wallets, prepaid credits, payouts owed |
| equity | credit | opening balances, an account that may go negative to mint test money |
| revenue | credit | fees, sales, breakage |
| expense | debit | promotions, chargebacks written off |

- `balance()` is on the normal side: a wallet with money owed to the user is positive.
- Codes are 1 to 190 characters: letters, digits and `: _ . / @ -`. They are case-sensitive.
  Codes containing `@hold:` and `@lot:` are made by the ledger for holds and credit lots.
- The currency is any code of 1 to 12 upper-case letters, digits or `_`: `USD`, `ETH`,
  `CREDIT`. The ledger does not know its scale. Amounts are integers in minor units, and
  formatting is your job.
- `open()` again with the same definition does nothing; with a different type, currency or
  `allowNegative` it throws `AccountConflict`. Accounts are not closed or changed.

## Writing

Every write takes an idempotency key (1 to 190 bytes), unique across the ledger. Use what
identifies the business event: `psp:ch_1001`, `order:A-17`, `job:7:capture`.

```php
$ledger->post($key, [Leg::debit('cash:psp', 50_00), Leg::credit('user:42', 50_00)], $meta, $type = 'transaction');
$ledger->transfer($key, $from, $to, $amount, $meta, $type = 'transfer');
$ledger->reverse($key, $transactionId, $meta);
```

- `post()` takes any number of legs. Per currency, debits must equal credits
  (`UnbalancedTransaction`). A transaction in two currencies balances each one separately.
- `transfer()` is for two accounts with the same normal side: the balance of `$from` goes
  down, the balance of `$to` goes up. Wallet to wallet, wallet to revenue. A deposit from an
  asset to a liability raises both; post it with legs.
- `$meta` is a flat map of strings, integers, booleans and null. Floats are refused: their
  text form is not stable, and the content hash depends on it.
- `$type` is a label of up to 64 characters, stored with the transaction. Types starting
  with `hold` and `credit.` are the ledger's own.
- An account that is not allowed to go negative refuses a transaction that would take it
  below zero (`InsufficientFunds`). Legs that raise a balance are applied before legs that
  lower it, so a transaction that deposits and charges a fee from an empty wallet works.

### Retries

The same key with the same content (type, legs in any order, meta, reversal link) returns
the stored transaction; nothing is written. Different content throws
`IdempotencyConflict` with the first 12 characters of both hashes.

### Reversals

`reverse()` posts every leg of the original on the other side, with type `reversal` and a
link to the original (`$transaction->reverses`, `reversalOf($id)`). A transaction is
reversed at most once (`AlreadyReversed`). A reversal is not reversed; post the original
again under a new key. Holds and credits are not reversed: release or capture a hold, and
refunds of used credits are not in this version (`NotReversible`). Reversing a deposit the
user already spent throws `InsufficientFunds`: refund the purchase first.

## Holds

```php
$hold = $ledger->hold('job:7', 'user:42', 3_00);        // Hold: id, account, holdAccount, amount
$ledger->capture('job:7:capture', $hold, 'revenue:fees', 2_37);
$ledger->release('job:7:release', $hold);               // all that remains, or pass an amount
$ledger->remaining($hold);                              // 0
$ledger->held('user:42');                               // total on open holds
$ledger->findHold($id);
```

A hold moves the amount to `user:42@hold:<24 hex>`, an account of the same type named after
the hold's key. A capture can be partial and repeated while something remains; it goes to
an account with the same normal side as the held one. Capturing more than remains throws
`InsufficientFunds`. The account code plus 30 characters must fit in 190.

## Credits

```php
$credits = $ledger->credits();
$lot = $credits->grant($key, 'customer:acme:credits', 50_00, from: 'cash:psp',
    revenue: 'revenue:jobs', breakage: 'revenue:breakage', expiresAt: $in30Days, meta: []);
$credits->consume($key, 'customer:acme:credits', 7_40, $meta);
$credits->available('customer:acme:credits', $at = null);
$credits->lots('customer:acme:credits');                // in the order they are used
$credits->expire('customer:acme:credits', $at = null);  // list of expiry transactions
```

- The credits account must be a liability. Its own balance stays 0: the credits are on its
  lots, `customer:acme:credits@lot:<expiry UTC>:<12 hex>`.
- `grant()` debits `from` and credits a new lot. `revenue` and `breakage` must be open and in
  the same currency; they are stored with the grant.
- `consume()` locks the account's lots, skips expired and empty ones, and takes from the
  lot that expires first; lots with the same expiry are ordered by a hash of their key, and
  lots without an expiry come last. Each lot's share is credited to that lot's revenue
  account. A retry with the same key, amount and meta returns the first result even though
  the lots have changed since.
- `expire()` moves what is left on each expired lot to its breakage account, one transaction
  per lot with the key `credits:expire:<grant id>`. Running it again does nothing. Expired
  lots are never used, so the order between `consume()` and `expire()` does not matter for
  the customer; it matters for when breakage is recognized. Call it per account, for
  example from a nightly job over your customers.

## Checking

```php
$result = $ledger->verify();     // Verification: accounts, transactions, postings, violations
$result->ok();
foreach ($result->violations as $v) { echo $v->kind, ' ', $v->message, "\n"; }
```

| Kind | Meaning |
|---|---|
| `chain_gap` | a sequence number is missing in an account's postings |
| `balance_mismatch` | the stored balance after a posting is not the sum of the postings so far |
| `negative_balance` | an account that must not go negative is below zero |
| `unbalanced` | a transaction's debits and credits differ in a currency |
| `hash_mismatch` | a transaction's legs, meta or reversal link are not what was written |
| `reversal_mismatch` | a reversal does not mirror the transaction it reverses |
| `no_postings` | a transaction has no postings |

`vendor/bin/ledger verify` runs the same check from the command line (see the README).

## Errors

All refusals extend `IanFoxDev\Ledger\Exception\LedgerException`: `UnknownAccount`,
`AccountConflict`, `UnbalancedTransaction`, `InsufficientFunds`, `IdempotencyConflict`,
`AlreadyReversed`, `NotReversible`, `ConcurrentWrite`. Invalid arguments (a
fractional amount, a key that is too long) throw `InvalidArgumentException`. Inside your own
transaction, roll back after any of them before using the connection again: in PostgreSQL a
failed statement ends the transaction.
