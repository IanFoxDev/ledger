# Deposits through a payment provider

A small PHP app that takes deposits through a payment provider and books them in the
ledger, run against [psp-sandbox](https://github.com/IanFoxDev/psp-sandbox), which
fails the way providers do. PostgreSQL, the sandbox and the app start with one command;
the tests drive a deposit through each failure and read the ledger tables to see what
was booked.

```bash
docker compose up -d --build --wait
docker compose exec app vendor/bin/phpunit
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
provider's side. The test asserts the gap. Finding it is the job of reconciliation,
which compares the ledger with the provider's report.

## Running it against your own code

The ledger is installed from this repository (`../..`, see
[composer.json](composer.json)), so the example runs against the code next to it. In
your app it is `composer require ianfoxdev/ledger` and the tables from
[schema/postgresql.sql](../../schema/postgresql.sql).
