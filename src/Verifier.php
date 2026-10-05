<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

use IanFoxDev\Ledger\Storage\Store;

/**
 * Recalculates the ledger from its postings and lists what does not hold. Reads in
 * batches; keeps the accounts and the per-transaction totals it needs in memory.
 *
 * @internal use Ledger::verify()
 */
final class Verifier
{
    private const int BATCH = 500;

    public function __construct(private readonly Store $store) {}

    public function run(): Verification
    {
        $violations = [];
        /** @var array<string, Account> $accounts */
        $accounts = [];
        $postings = 0;

        foreach ($this->store->allAccounts() as $account) {
            $accounts[$account->code] = $account;
            $balance = Amount::zero();
            $expected = 1;
            foreach ($this->store->chain($account->code) as $posting) {
                $postings++;
                if ($posting->sequence !== $expected) {
                    $violations[] = new Violation(Violation::CHAIN_GAP, \sprintf('Account "%s": posting #%d follows #%d.', $account->code, $posting->sequence, $expected - 1), $account->code);
                }
                $expected = $posting->sequence + 1;
                $balance = $balance->plus($account->direction($posting->side) > 0 ? $posting->amount : $posting->amount->negated());
                if (!$balance->equals($posting->balanceAfter)) {
                    $violations[] = new Violation(Violation::BALANCE_MISMATCH, \sprintf('Account "%s" #%d: the postings add up to %s, the stored balance is %s.', $account->code, $posting->sequence, $balance, $posting->balanceAfter), $account->code);
                    // Continue from the stored value, so one bad row is reported once.
                    $balance = $posting->balanceAfter;
                }
                if ($balance->isNegative() && !$account->allowNegative) {
                    $violations[] = new Violation(Violation::NEGATIVE_BALANCE, \sprintf('Account "%s" #%d is at %s and must not go negative.', $account->code, $posting->sequence, $balance), $account->code);
                }
            }
        }

        $transactions = 0;
        $after = 0;
        while (($batch = $this->store->transactionsAfter($after, self::BATCH)) !== []) {
            foreach ($batch as $transaction) {
                $transactions++;
                $after = $transaction->id;
                array_push($violations, ...$this->checkTransaction($transaction, $accounts));
            }
        }

        return new Verification(\count($accounts), $transactions, $postings, $violations);
    }

    /**
     * @param array<string, Account> $accounts
     * @return list<Violation>
     */
    private function checkTransaction(Transaction $transaction, array $accounts): array
    {
        $id = $transaction->id;
        if ($transaction->postings === []) {
            return [new Violation(Violation::NO_POSTINGS, \sprintf('Transaction %d ("%s") has no postings.', $id, $transaction->key), null, $id)];
        }
        $violations = [];
        $net = [];
        $legs = [];
        foreach ($transaction->postings as $posting) {
            $currency = isset($accounts[$posting->account]) ? $accounts[$posting->account]->currency : '?';
            $net[$currency] = ($net[$currency] ?? Amount::zero())->plus($posting->side === Side::Debit ? $posting->amount : $posting->amount->negated());
            $legs[] = new Leg($posting->account, $posting->side, $posting->amount);
        }
        foreach ($net as $currency => $difference) {
            if (!$difference->isZero()) {
                $violations[] = new Violation(Violation::UNBALANCED, \sprintf('Transaction %d ("%s"): debits and credits in %s differ by %s.', $id, $transaction->key, $currency, $difference), null, $id);
            }
        }
        if (Canonical::hash($transaction->type, $legs, $transaction->meta, $transaction->reverses) !== $transaction->hash) {
            $violations[] = new Violation(Violation::HASH_MISMATCH, \sprintf('Transaction %d ("%s"): its postings or meta are not what was written.', $id, $transaction->key), null, $id);
        }
        if ($transaction->reverses !== null) {
            $original = $this->store->transaction($transaction->reverses);
            if ($original === null || self::mirrored($original) !== self::lines($transaction)) {
                $violations[] = new Violation(Violation::REVERSAL_MISMATCH, \sprintf('Transaction %d does not mirror transaction %d it reverses.', $id, $transaction->reverses), null, $id);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function lines(Transaction $transaction): array
    {
        $lines = array_map(static fn(Posting $p): string => $p->account . ' ' . $p->side->value . ' ' . $p->amount, $transaction->postings);
        sort($lines, \SORT_STRING);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function mirrored(Transaction $transaction): array
    {
        $lines = array_map(static fn(Posting $p): string => $p->account . ' ' . $p->side->opposite()->value . ' ' . $p->amount, $transaction->postings);
        sort($lines, \SORT_STRING);

        return $lines;
    }
}
