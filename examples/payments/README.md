# Deposits through a payment provider

A small PHP app that takes deposits through a payment provider and books them in the
ledger, run against [psp-sandbox](https://github.com/IanFoxDev/psp-sandbox), which
fails the way providers do. PostgreSQL, the sandbox and the app start with one command;
the tests drive a deposit through each failure and read the ledger tables to see what
was booked.

```bash
docker compose up -d --build --wait
docker compose exec app vendor/bin/phpunit             # deposits through every failure
docker compose exec app php bin/provider-report.php    # the provider's report to exports/
docker compose run --rm recon                          # ledger against the report, exit 1
docker compose exec app vendor/bin/phpunit --group recon
docker compose run --rm money-checks                   # the ledger's invariants, exit 0
```

The sandbox UI at http://localhost:8090/_sandbox/ shows every callback and what the app
answered.

## The app

[public/index.php](public/index.php) has three routes. `POST /deposits` opens the user's
wallet and creates a payment with the user in its metadata. `POST /refunds` asks the
provider for a refund and books nothing. `POST /psp/callback` checks the signature and
books what the provider says happened, through [src/Books.php](src/Books.php):

| Event | Ledger transaction | Key |
|---|---|---|
| `payment.captured` | `deposit`: debit `cash:psp`, credit `user:<id>` | `psp:<payment id>:captured` |
| `refund.succeeded` | `deposit.refund`: debit `user:<id>`, credit `cash:psp` | `psp:<refund id>` |

The app keeps no table of its own. Each write is keyed by the provider's id, and the
ledger writes a key once: a repeated key with the same content returns the stored
transaction, so the handler needs no "already processed" check.

## The failures

The deposit reference picks the scenario ([scenarios.yaml](scenarios.yaml)), so the app
does not know it runs against a sandbox.

**`dup-`: five copies of `payment.captured` at once.** FrankenPHP runs them in parallel
threads. All five post under the same key; the ledger locks the accounts, one copy
writes, the other four get the stored transaction. Every copy is answered 200 and the
wallet has the deposit once.

**`ooo-`: the refund callback before the capture callback.** The test asks for a refund
while the provider still holds the capture callback, and both come newest first. A
handler that books each event as it comes cannot book the refund: the wallet is empty,
the ledger throws `InsufficientFunds`, the callback fails until the provider gives up.
This handler treats the callback as a hint. It fetches the payment from the provider,
books its capture under the key the capture callback will use, then the refund. When
`payment.captured` arrives, its key is already taken with the same content.

**`lost-`: no callback at all.** The provider captured the money and the ledger has
nothing. The app cannot see this from inside: the deposit only exists on the
provider's side. The test asserts the gap, and reconciliation finds it.

## Reconciliation

Every morning finance downloads the provider's Balance report and compares it with the
books. Here [bin/provider-report.php](bin/provider-report.php) downloads it from the
sandbox, which serves Stripe's itemized format, and
[recon](https://github.com/IanFoxDev/recon) compares it with the ledger.

recon reads the ledger's own tables: every posting on `cash:psp` with the payment id
from the transaction's meta, set up in one line of [recon.yaml](recon.yaml) with its
`ianfoxdev_ledger` source. No export table to keep in step: the tables are a read
contract ([ADR 0002](../../docs/adr/0002-the-schema-is-a-read-contract.md)). recon sums
both sides per payment, so a deposit with a refund is compared as one net amount.

After the four tests:

```
reconciled 3, differences 1, missing_in_ledger 1
```

The three deposits that got their callbacks reconcile: the duplicated one was booked
once, the one refunded out of order nets to the same amount on both sides. The fourth
is the lost callback: on the provider's report, not in the ledger. recon exits with 1,
and `out/report.html` names the payment. `ReconTest` checks that this is the only
difference and that it is a `lost-` deposit.

What to do with it is a business decision the example leaves out: book the deposit from
the provider's data under the same key the callback would have used, so a late callback
changes nothing.

## Invariants

Reconciliation compares the ledger with the outside world. The ledger's own rules (every
transaction balances, every `balance_after` follows from the one before, no account
below zero without permission, no expired credits left unexpired) are checked by
[money-checks](https://github.com/IanFoxDev/money-checks) with its
[ledger recipes](https://github.com/IanFoxDev/money-checks/tree/master/recipes/ledger),
copied into [checks/](checks) as they are. They read the tables, like recon, and need no
PHP. After the tests all five pass; a `balance_after` changed by hand in psql shows up
in `ledger_balance_chain` on the next run.

## Running it against your own code

The ledger is installed from this repository (`../..`, see
[composer.json](composer.json)), so the example runs against the code next to it. In
your app it is `composer require ianfoxdev/ledger` and the tables from
[schema/postgresql.sql](../../schema/postgresql.sql).
