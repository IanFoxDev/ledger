# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may break the
API; such changes are marked **BREAKING**.

## [Unreleased]

## [0.2.0] - 2026-11-17

The ledger is now readable by tools outside PHP: the schema is a stated contract, SQL for
the usual reports is tested, and an example books provider callbacks, reconciles them
with recon and checks the tables with money-checks. Credits can be refunded and expired
in bulk, and every posting can be published through the outbox.

### Added

- The schema is a contract for readers: the tables, their columns and what they mean keep
  the same compatibility promise as the PHP API (ADR 0002). `docs/reading.md` has SQL for
  balances, statements, turnover, a trial balance and reconciliation exports, and a test
  runs every query in it on PostgreSQL and MySQL.
- `Credits::refund()` gives back what a consumption used, all or a part, to the lots it
  came from, and debits their revenue accounts. A consumption is refunded once. Credits
  returned to an expired lot stay expired unless `reopenUntil` moves them to a new lot
  (ADR 0003). New transaction type `credit.refund`; meta keys `reopen_until`, `reopens`.
- `Credits::expireAll()` expires the lots of every credits account, one transaction per
  lot, in batches with a cursor. `ledger credits:expire` runs it from cron and prints the
  totals per breakage account.
- `TransactionListener`: the ledger calls `posted()` for every transaction it writes,
  inside the database transaction. Listeners are passed to the `Ledger` constructor.
- `Outbox\OutboxListener` records a `ledger.account.posted` event per posting in
  `ianfoxdev/outbox` (optional, in `suggest`), keyed by account, with the sequence and
  the balance after it (ADR 0004).
- `examples/payments`: deposits booked from payment provider callbacks, tested with
  docker compose against psp-sandbox for duplicate, out of order and lost callbacks,
  then reconciled with recon against the provider's Balance report and checked with
  the money-checks recipes for the ledger's tables.

### Changed

- **BREAKING** for custom stores: `Store` has a new method, `codesContaining()`.
- `expire()` can run for a lot again after a refund refilled it. The first expiry keeps
  the key `credits:expire:<lot id>`; later ones are `credits:expire:<lot id>:<n>`.
- `verify()` accepts a `credit.refund` that is a part of the consumption it is linked to.
- `Ledger::reverse()` on a consumption of credits names `Credits::refund()` in its
  `NotReversible` message.

## [0.1.0] - 2026-10-05

The first release: a double-entry ledger that writes in the application's transaction,
with derived balances, idempotent writes, reversals, holds, expiring credits and a check
that recalculates everything. PostgreSQL and MySQL.

### Added

- Accounts with a type and its normal side; transactions of debit and credit legs that
  must balance per currency; balances derived from postings (each posting stores its
  sequence number and the balance after it). Amounts are integers in minor units with
  `brick/math`, stored as `NUMERIC(38,0)`.
- `post()`, `transfer()`, `balance()`, with a required idempotency key: the same content
  returns the stored transaction, different content throws `IdempotencyConflict`.
- `reverse()`: a linked transaction with every side swapped, at most once per transaction.
- Holds: `hold()`, `capture()` (partial, repeated), `release()`, `held()`, each hold on an
  account of its own.
- `Credits`: lots that expire, usage from the lot that expires first, `expire()` to a
  breakage account, `available()`, `lots()`.
- `verify()` and `bin/ledger verify`: chains, balances, negative balances, balanced
  transactions, a content hash per transaction, reversals.
- `PdoStore` for PostgreSQL and MySQL with schemas in `schema/`; joins the application's
  transaction, opens its own at READ COMMITTED. `InMemoryStore` for tests.

[Unreleased]: https://github.com/IanFoxDev/ledger/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/IanFoxDev/ledger/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/IanFoxDev/ledger/releases/tag/v0.1.0
