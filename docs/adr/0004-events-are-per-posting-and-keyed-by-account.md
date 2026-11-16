# 0004. Events are per posting and keyed by account

Date: 2026-11-16. Status: accepted.

## Context

Other services need to know that money moved: a notification that a deposit arrived, a
balance in a read model, a fraud rule. Announcing it after the commit loses the event when
the process dies in between; announcing it before the commit announces money that may
never be written. The event has to be stored in the transaction that writes the postings
and published later, which is what an outbox does.

The ledger must not depend on one. And the shape of the event decides whether a consumer
can trust what it builds from it.

## Decision

- **The core has an interface and nothing else.** `TransactionListener::posted()` is
  called for every transaction the ledger writes, inside the database transaction that
  writes it. A listener that throws undoes the write. A retry that returns a stored
  transaction calls nobody: one call per row in `ledger_transactions`.
- **The adapter is optional.** `Outbox\OutboxListener` records the events in
  `ianfoxdev/outbox`, which is in `suggest`, not in `require`.
- **One event per posting, with the account as the aggregate.** A transaction moves money
  between accounts, and a consumer nearly always follows an account: this wallet, this
  provider. With the account as the key the relay keeps an account's events in the order
  of its postings, on one partition.
- **The event carries `sequence` and `balance_after`.** A consumer that remembers the last
  sequence it applied drops a redelivered event (sequence not above the last), sees a gap
  (sequence above the last plus one) and can set its balance from `balance_after` instead
  of adding amounts. Adding amounts is how a read model drifts after one duplicate.
- **Amounts are strings**, as in the database: they can be longer than a JSON number holds.

An event per transaction with the idempotency key as the aggregate was the other option.
It gives no order between two transactions of one account, so every consumer would have
to sort out for itself which balance is the latest.

## Consequences

- A transfer between two wallets is two events on two partitions. A consumer that needs
  the transaction as a whole reads `transaction.id` from both, or reads the ledger tables
  (ADR 0002).
- A busy account produces an event per write. The listener takes a filter to leave out
  hold and lot accounts or a revenue account nobody follows.
- The payload is a contract too: `account`, `sequence`, `side`, `amount`,
  `balance_after` and `transaction` (`id`, `key`, `type`, `meta`, `reverses`,
  `created_at`). Fields can be added; these do not change without a breaking release.
- Delivery is at least once, as with any outbox. `(account, sequence)` is the
  deduplication key.
