# ledger

Double-entry ledger for PHP that runs inside your application's database transaction.
Balances are derived from postings, nothing is updated or deleted, and a check
recalculates everything from the postings.

> Status: in development, nothing released yet.

Why balances are derived and how concurrent writes are handled:
[docs/adr/0001-balances-are-derived-and-nothing-is-edited.md](docs/adr/0001-balances-are-derived-and-nothing-is-edited.md).

## License

[MIT](LICENSE)
