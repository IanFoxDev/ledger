# 0003. Refunds of used credits

Date: 2026-11-16. Status: accepted.

## Context

A job is paid for with credits when it starts. Then it fails, or costs less than was
taken, and the credits must go back. In 0.1 a consumption could not be undone
(`NotReversible`), so the only way to give credits back was a new grant: a lot with a new
expiry and a promotion expense as its source. That leaves revenue that was never earned
in the books, adds an expense that was never spent, and gives the customer credits that
outlive the ones they bought.

A plain reversal does not fit either. It mirrors the whole transaction, and a refund is
often partial. And it knows nothing about lots that have expired since the job started.

## Decision

`Credits::refund($key, $consumptionId, $amount = null, $reopenUntil = null)` posts a
`credit.refund` transaction linked to the consumption.

- **Credits go back to the lots they came from.** Each lot is credited and its revenue
  account is debited. A lot keeps its expiry: a refund does not extend it.
- **A partial refund undoes the end of the consumption.** Usage takes from the lot that
  expires first; a refund fills the lot that expires last first. After a job that took
  12.00 is refunded 5.00, the lots hold what they would hold had the job taken 7.00.
  The customer keeps the credits with the longest life, and the result does not depend on
  whether the job was priced before or after it ran.
- **A consumption is refunded once.** The refund uses the same unique link as a reversal
  (`ledger_transactions.reverses`), so the database refuses a second one
  (`AlreadyReversed`), including when two processes refund the same job under different
  keys at the same moment. An application that needs to give back in several steps
  consumes in several steps.
- **Credits returned to an expired lot stay expired.** This is the default. They sit on
  the lot, cannot be used, and the next `expire()` moves them to the lot's breakage
  account. In the books the revenue of the job becomes breakage, which is what happened:
  the service was not delivered and the credits ran out.
- **The caller can reopen them.** With `$reopenUntil` the part that landed on an expired
  lot is moved to a new lot with that expiry and the same revenue and breakage accounts,
  by a `credit.grant` with the key `credits:reopen:<refund id>:<lot id>` and
  `reopens: <lot id>` in its meta. Lots that have not expired are not touched by it.

Why the default is the strict one: who is at fault is not something a ledger knows. A job
that failed on the provider's side an hour after the customer's credits expired deserves
them back; a customer who cancels a queued job after the expiry does not. Inventing a
grace period inside the library would hide a product decision in a dependency. The
default gives the same books on every call, and the kinder path is one argument away.

Whether a lot had expired is decided by the time the refund was written, not by the time
of the call. A retry of the same refund three days later reopens the same lots under the
same keys and changes nothing.

## Consequences

- `expire()` can now run for a lot more than once: after a refund put credits back on a
  lot it had already emptied. The first expiry of a lot keeps the key
  `credits:expire:<lot id>`; a later one adds the number of its posting on the lot
  (`credits:expire:<lot id>:<n>`).
- The check accepts a `credit.refund` whose postings are on the other side of the
  consumption's postings and not larger. Any other linked transaction must still mirror
  its original exactly.
- The refund debits the revenue account the lot names. If that account must not go
  negative and the revenue was already moved elsewhere, the refund throws
  `InsufficientFunds`, like any other transaction would.
- One refund per consumption is a limit. Lifting it needs a way to add up earlier refunds
  under a lock, which the current schema does not give without a scan.
- Returning a purchase of credits for money is a different operation and needs no new
  code: post a transaction that debits the lot account and credits the cash account for
  what is left on the lot.
