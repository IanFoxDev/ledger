# Contributing

## Running locally

You need PHP 8.3 or later, Composer and Docker.

```bash
composer install
make databases-up    # PostgreSQL on 55435, MySQL (Percona Server 8.4) on 33308
make check           # PHP-CS-Fixer, PHPStan at level max, PHPUnit
make databases-down
```

Without the databases the storage tests are skipped, not failed. The Makefile sets
`LEDGER_PG_DSN` and `LEDGER_MYSQL_DSN` for the containers above. With them, every scenario
in `tests/LedgerTest.php` and `tests/CreditsTest.php` also runs on PostgreSQL and MySQL,
and `tests/Storage/ConcurrencyTest.php` starts eight writer processes against each.

`php bench/transfers.php DSN [processes] [transfers] [accounts]` measures transfers per
second; it recreates the ledger tables in the database it is given.

## Where things are

| Path | What |
|---|---|
| `src/Ledger.php` | Accounts, posting, transfers, reversals, holds; the write path and its locks |
| `src/Credits.php`, `src/Lot.php` | Credits that expire |
| `src/Verifier.php` | The check behind `verify()` and `bin/ledger` |
| `src/Storage/PdoStore.php`, `schema/` | PostgreSQL and MySQL |
| `src/Storage/InMemoryStore.php` | For tests |
| `tests/InvariantTest.php` | Thousands of random transactions; the books must balance after each |
| `tests/Storage/PdoStoreTest.php` | Retries from an old snapshot, reversals racing, chains that must not fork |
| `examples/saas` | The example; its output is checked by the tests |

## Changing the write path

A change to how transactions are written has to keep `tests/Storage/ConcurrencyTest.php`
at zero conflicts and zero deadlocks on both databases, and `verify()` clean afterwards.
Run it a few times: a race that shows up once in five runs is still a race. A change to
what goes into a transaction's hash (`src/Canonical.php`) makes `verify()` report every
existing transaction and is **BREAKING**.

## Pull requests

- One logical change per pull request; Conventional Commits.
- `make check` passes with the databases up. Add a line to `CHANGELOG.md`.
