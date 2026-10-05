# 0001. Balances are derived from postings, and nothing is edited

Date: 2026-10-05. Status: accepted.

## Context

Most PHP wallets keep a `balance` column and run `UPDATE accounts SET balance = balance + ?`.
That fails in ways that are found late.

- **Two requests race.** Both read a balance of 10.00, both spend 8.00, both succeed.
- **The question "why is the balance this" has no answer.** The column holds a number;
  the history next to it is a log that nothing forces to agree with it. When they
  disagree, nobody can say which one is right.
- **Mistakes are fixed in place.** A wrong row is updated or deleted, and the trail that
  reconciliation and audit need is gone.
- **Money leaks between accounts.** A deposit is added to the user and never taken from
  anywhere, so the books do not balance, and nothing notices.

## Decision

- **Double entry.** A transaction is a set of legs; each leg debits or credits one account.
  Per currency, the debits of a transaction equal its credits, or it throws before anything
  is written. Money is never created or lost inside the ledger, only moved.
- **Accounts have a type** (asset, liability, equity, revenue, expense), which fixes their
  normal side. The balance of a debit-normal account (asset, expense) is debits minus
  credits; of a credit-normal one (liability, equity, revenue), credits minus debits. A
  user's wallet is a liability of the platform, so its balance is positive when the
  platform owes the user money.
- **A balance is derived from postings.** Every posting stores the account's sequence
  number and the balance after it. The current balance is the last posting's balance; the
  check recalculates every chain from the amounts. There is no balance column to update.
- **Only inserts.** Postings and transactions are never updated or deleted. A mistake is
  undone by a reversal: a new transaction with the sides swapped and a link to the original.
  An original can be reversed once.
- **Amounts are integers in minor units**, as arbitrary-precision integers (`brick/math`),
  stored as `NUMERIC(38,0)` / `DECIMAL(38,0)`. 1.5 ETH is 1500000000000000000 wei, which a
  64-bit integer cannot hold. No floats anywhere. The ledger does not know the scale of a
  currency; formatting is the caller's job.
- **Concurrency is decided by locks and a key.** A transaction locks the rows of its
  accounts with `SELECT ... FOR UPDATE`, always in the order of account code, so two
  transactions cannot deadlock on each other. The primary key of a posting is (account,
  sequence): if a write slips past the lock, it fails instead of forking the chain.
  Accounts that must not go negative are checked under the lock.
- **Every transaction has an idempotency key.** Posting the same key with the same content
  returns the stored transaction; with different content it throws, naming both hashes. A
  retried request does not move money twice.
- **The ledger runs in the application's transaction.** It takes the application's PDO
  connection. If a transaction is already open, the ledger joins it, so the business row
  and the postings commit or roll back together; otherwise it opens and commits its own.

## Consequences

- A dependency on `brick/math`, the same as in payout-split. It is pure PHP and uses GMP or
  BCMath when they are installed.
- Reading a balance is one indexed query for the last posting of the account. Recalculating
  from scratch is the check's job, not every request's.
- Hot accounts serialize. A platform revenue account that every purchase credits is locked
  by every purchase. That is the price of a correct balance; a later version may add
  accounts that are written without a lock and summed on read.
- A transaction that touches accounts in two currencies must balance in each currency
  separately. Conversion with a rate is not in v0.1.
- Fixing a mistake leaves two transactions in the history instead of none. That is the
  point.
