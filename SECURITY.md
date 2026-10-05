# Security

ledger computes balances in your process and stores them through the PDO connection you
give it. It makes no network requests.

If you find a vulnerability, for example a sequence of calls that moves money twice for
one key, takes an account below zero that must not go there, or a change to stored rows
that `verify()` does not report, do not open a public issue. Report it privately through
[GitHub](https://github.com/IanFoxDev/ledger/security/advisories/new), or write to
ianfoxdeveloper@gmail.com.

## Supported versions

Fixes go into the latest release only. Until 1.0 that is the latest `0.x` tag.
