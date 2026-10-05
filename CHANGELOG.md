# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may break the
API; such changes are marked **BREAKING**.

## [Unreleased]

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
