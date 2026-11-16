# 0002. The schema is a contract for readers

Date: 2026-11-16. Status: accepted.

## Context

The ledger is written through the library and read by everything else: a reconciliation
job, invariant checks, a finance export, a support screen, a BI tool. None of them is PHP
code that can call `Ledger::balance()`. They run SQL against the three tables.

Without a stated contract that goes one of two ways. Either the tables are treated as
private, and every reader gets a hand-made export that drifts from the books. Or readers
query the tables anyway, and a column renamed in a patch release breaks a nightly
reconciliation that nobody watches until the month closes.

## Decision

The three tables are a public interface for reading, with the same compatibility promise
as the PHP API. Writing stays private: rows are inserted by the library only.

**What a reader can rely on**

- The table names `ledger_accounts`, `ledger_transactions`, `ledger_postings`, and every
  column in `schema/postgresql.sql` and `schema/mysql.sql`, with its name and type.
- `ledger_postings.sequence` starts at 1 for each account and has no gaps.
- `ledger_postings.balance_after` is the balance of the account after that posting, on
  the account's normal side: debits minus credits for asset and expense accounts, credits
  minus debits for liability, equity and revenue. The last posting of an account holds its
  current balance; an account with no postings has 0.
- `ledger_postings.amount` is positive, in minor units; `side` is `D` or `C`.
- `ledger_postings.position` numbers the postings of a transaction in the order they were
  applied: by account code, not in the order the caller listed the legs.
- `ledger_transactions.created_at` is UTC and comes from the application's clock.
- `ledger_transactions.reverses` is the id of the transaction this one undoes, or NULL.
  At most one transaction undoes another. A `reversal` mirrors its original in full; a
  `credit.refund` can undo a part of a `credit.consume`.
- `ledger_transactions.meta` is a JSON object with scalar values, stored as text.
- Rows are inserted and never updated or deleted.
- The codes the ledger makes itself: `<account>@hold:<24 hex>` for a hold and
  `<account>@lot:<expiry in UTC as YYYYMMDDTHHMMSSZ>:<12 hex>` for a
  credit lot. An account whose code contains neither is one the application opened.
- The transaction types the ledger writes: `transaction`, `transfer`, `reversal`, `hold`,
  `hold.capture`, `hold.release`, `credit.grant`, `credit.consume`, `credit.expire`,
  `credit.refund`, and the meta keys it sets on them (`hold`, `credits`, `lot`, `request`,
  `expires_at`, `revenue`, `breakage`, `reopen_until`, `reopens`).

**What can be added without notice in a minor version**

- A column that is nullable or has a default, a table with the `ledger_` prefix, an index.
- A transaction type starting with `hold` or `credit.`, and a meta key on those types.

So a reader names its columns instead of `SELECT *`, does not fail on a type it has not
seen, and does not create tables of its own with the `ledger_` prefix.

**What needs a breaking release**

Removing or renaming a table or a column, changing its type or meaning, changing the sign
of `balance_after`, changing the format of hold and lot codes. Before 1.0 such a change
is marked **BREAKING** in the changelog and ships with the SQL to migrate both databases;
from 1.0 it waits for a major version.

**What a reader must not do**

- Write, including "fixing" one row. `verify()` exists to find exactly that.
- Lock. `SELECT ... FOR UPDATE` or `FOR SHARE` on these tables makes writers wait, and a
  writer holds the locks of every account in its transaction. Read without locks, or from
  a replica.
- Depend on indexes the schema does not declare. The library needs the primary keys, the
  unique key on `idempotency_key` and on `reverses`, and the index on
  `ledger_postings (transaction_id)`. Reports by time need an index on
  `ledger_transactions (created_at)`; add it, the library does not mind.

**The hash is not part of the contract.** `ledger_transactions.hash` is for `verify()` and
for idempotency. Its input can change between versions as long as stored transactions
still verify.

## Consequences

- Reconciliation, invariant checks and exports read the tables directly. There is no
  export format to keep in step with the books, because the books are the export.
- `docs/reading.md` has the queries readers need most. A test runs every one of them on
  PostgreSQL and MySQL, so a schema change that breaks a documented query fails the build.
- The order of rows across accounts is the weak spot, and the contract says so: ids of
  `ledger_transactions` are handed out before commit, so a reader that pages by
  "id greater than the last one seen" can skip a transaction that committed late. Inside
  one account the order is safe: a writer holds the account's lock until commit, so
  posting N+1 never becomes visible before posting N. Incremental readers page by
  `(account, sequence)`.
- `created_at` is the clock of the server that wrote the row. Two application servers
  with skewed clocks can write postings whose time goes backwards along an account's
  sequence. A balance "at a moment" is therefore as exact as the clocks; the sequence is
  the order that counts.
- Renames are now expensive. A column name that turns out to be wrong stays until the next
  breaking release.
