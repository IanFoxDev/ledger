# Reading the ledger with SQL

The three tables are a public interface for reading; what is promised about them is in
[ADR 0002](adr/0002-the-schema-is-a-read-contract.md). The queries below run unchanged on
PostgreSQL and MySQL, and a test (`tests/Storage/ReadingTest.php`) runs each of them as
written here.

Three rules before the queries:

- Read without locks (`FOR UPDATE`, `FOR SHARE`), or from a replica. A lock taken by a
  report makes payments wait.
- Amounts are integers in minor units and can be longer than 64 bits. Read them as
  strings or decimals, not as floats.
- Name the columns. A minor version can add one.

## Balance of an account

```sql
SELECT balance_after
FROM ledger_postings
WHERE account = :account
ORDER BY sequence DESC
LIMIT 1
```

No row means the account has no postings and its balance is 0. The balance is on the
account's normal side: a wallet (liability) is positive when the user has money, a
provider account (asset) is positive when the provider holds money for you.

## Balance at a moment

```sql
SELECT p.balance_after
FROM ledger_postings p
JOIN ledger_transactions t ON t.id = p.transaction_id
WHERE p.account = :account AND t.created_at < :at
ORDER BY p.sequence DESC
LIMIT 1
```

`:at` is UTC, for example `2026-11-01 00:00:00` for the balance the month started with.
`created_at` is the clock of the application server that wrote the transaction, so the
answer is as exact as your clocks are.

## Balances of many accounts

```sql
SELECT a.code, a.type, a.currency, COALESCE(p.balance_after, 0) AS balance
FROM ledger_accounts a
LEFT JOIN ledger_postings p ON p.account = a.code
    AND p.sequence = (SELECT MAX(sequence) FROM ledger_postings WHERE account = a.code)
WHERE a.code LIKE :pattern
ORDER BY a.code
```

- `%` gives every account: the balance sheet.
- `user:42@hold:%` gives the open and closed holds of a wallet; the ones with a balance
  above 0 are still open, and their sum is the money on hold.
- `customer:acme:credits@lot:%` gives the credit lots of a customer in the order they are
  used. The part after `@lot:` is the expiry in UTC (`20261205T120000Z`), or
  `99991231T235959Z` for a lot that does not expire.

`_` is a wildcard in `LIKE`. If your codes contain it, escape it in the pattern.

## Statement of an account

```sql
SELECT p.sequence, t.created_at, t.type, t.idempotency_key, p.side, p.amount, p.balance_after
FROM ledger_postings p
JOIN ledger_transactions t ON t.id = p.transaction_id
WHERE p.account = :account AND p.sequence < :before
ORDER BY p.sequence DESC
LIMIT 50
```

The newest 50 lines before `:before`; pass a large number for the first page and the
smallest `sequence` of a page to get the next one. The page does not shift when new
postings arrive, which `OFFSET` cannot promise.

## Everything one business event wrote

```sql
SELECT t.id, t.type, t.created_at, t.reverses, p.account, p.side, p.amount, p.balance_after
FROM ledger_transactions t
JOIN ledger_postings p ON p.transaction_id = t.id
WHERE t.idempotency_key = :key
ORDER BY p.position
```

The key is what your application passed to `post()`: `psp:ch_1001`, `order:A-17`. To find
out whether the transaction was undone:

```sql
SELECT id, idempotency_key, created_at
FROM ledger_transactions
WHERE reverses = :id
```

## Turnover of each account in a period

```sql
SELECT p.account,
    SUM(CASE WHEN p.side = 'D' THEN p.amount ELSE 0 END) AS debits,
    SUM(CASE WHEN p.side = 'C' THEN p.amount ELSE 0 END) AS credits
FROM ledger_postings p
JOIN ledger_transactions t ON t.id = p.transaction_id
WHERE t.created_at >= :from AND t.created_at < :to
GROUP BY p.account
ORDER BY p.account
```

The schema has no index on `created_at`, because the library never reads by time. For
reports like this one add `CREATE INDEX ledger_transactions_created_at ON
ledger_transactions (created_at)`.

## Trial balance

```sql
SELECT a.currency,
    SUM(CASE WHEN p.side = 'D' THEN p.amount ELSE 0 END) AS debits,
    SUM(CASE WHEN p.side = 'C' THEN p.amount ELSE 0 END) AS credits
FROM ledger_postings p
JOIN ledger_accounts a ON a.code = p.account
GROUP BY a.currency
ORDER BY a.currency
```

In every row `debits` equals `credits`. If they differ, money appeared or vanished inside
the ledger; run `vendor/bin/ledger verify` to find the transaction.

## Movements on one account, for reconciliation and exports

```sql
SELECT p.sequence, t.idempotency_key, t.type, t.created_at, t.reverses,
    CASE WHEN p.side = 'D' THEN p.amount ELSE -p.amount END AS amount
FROM ledger_postings p
JOIN ledger_transactions t ON t.id = p.transaction_id
WHERE p.account = :account AND p.sequence > :after
ORDER BY p.sequence
LIMIT 1000
```

This is how a job that compares the ledger with a provider's report reads the provider's
account (`cash:psp`). `amount` is signed as a debit: on an asset account a deposit is
positive and a refund is negative. Keep the last `sequence` you processed and pass it as
`:after` next time.

Page by `(account, sequence)`, never by `ledger_transactions.id`. Ids are handed out
before commit: transaction 1001 can become visible after 1002, and a reader that already
moved past 1001 never sees it. Inside one account that cannot happen, because a writer
holds the account's lock until it commits.

## Meta

`meta` is a JSON object stored as text. Cast it where you need a field:

```sql
-- PostgreSQL
SELECT id, meta::jsonb ->> 'order' AS order_id FROM ledger_transactions WHERE type = 'transfer';
-- MySQL
SELECT id, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.order')) AS order_id FROM ledger_transactions WHERE type = 'transfer';
```

The keys `hold`, `credits`, `lot`, `request`, `expires_at`, `revenue` and `breakage` are
set by the ledger on its own transaction types; do not reuse them for something else.
