<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

use IanFoxDev\Ledger\Exception\IdempotencyConflict;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Exception\NotReversible;
use IanFoxDev\Ledger\Exception\UnknownAccount;
use IanFoxDev\Ledger\Storage\Head;
use IanFoxDev\Ledger\Storage\Store;

/**
 * Prepaid credits that expire. Every grant is a lot on an account of its own; usage takes
 * from the lot that expires first; what is left when a lot expires goes to a breakage
 * account. The customer's credits account is a liability: the platform owes the service
 * until the credits are used or expire.
 *
 * Get it from Ledger::credits().
 */
final class Credits
{
    private const string NEVER = '99991231T235959Z';
    private const int PAGE = 500;

    /**
     * @internal
     */
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Store $store,
        private readonly Clock $clock,
    ) {}

    /**
     * Grants credits: debits $from (cash received, a wallet, a promotion expense) and puts
     * the amount on a new lot. Using the lot credits $revenue; on expiry what is left
     * credits $breakage.
     *
     * @param array<mixed> $meta string keys to strings, integers, booleans or null
     */
    public function grant(
        string $key,
        string $account,
        int|string|Amount $amount,
        string $from,
        string $revenue,
        string $breakage,
        ?\DateTimeImmutable $expiresAt = null,
        array $meta = [],
    ): Lot {
        $base = $this->liability($account);
        foreach ([$revenue, $breakage] as $code) {
            $target = $this->store->account($code) ?? throw new UnknownAccount(\sprintf('Account "%s" is not open.', $code));
            if ($target->currency !== $base->currency) {
                throw new \InvalidArgumentException(\sprintf('Account "%s" is in %s, the credits are in %s.', $code, $target->currency, $base->currency));
            }
        }
        $expiry = $expiresAt === null ? self::NEVER : $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $lotAccount = $account . '@lot:' . $expiry . ':' . substr(hash('sha256', $key), 0, 12);
        if (\strlen($lotAccount) > 190) {
            throw new \InvalidArgumentException(\sprintf('Account "%s" is too long for credits: a lot adds 35 characters to the code, up to 190.', $account));
        }
        $amount = $amount instanceof Amount ? $amount : Amount::of($amount);
        $reserved = [
            'credits' => $account,
            'expires_at' => $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'revenue' => $revenue,
            'breakage' => $breakage,
        ];
        $grant = $this->store->transactional(function () use ($key, $base, $lotAccount, $from, $amount, $reserved, $meta): Transaction {
            $this->ledger->open(new Account($lotAccount, $base->type, $base->currency));

            return $this->ledger->post($key, [Leg::debit($from, $amount), Leg::credit($lotAccount, $amount)], $reserved + $meta, 'credit.grant');
        });

        return $this->lotOf($lotAccount, $this->ledger->balance($lotAccount), $grant);
    }

    /**
     * Every lot of the account, in the order they are used: soonest expiry first; lots
     * without an expiry last.
     *
     * @return list<Lot>
     */
    public function lots(string $account): array
    {
        return array_map(fn(Head $head): Lot => $this->lotOf($head->account->code, $head->balance), $this->store->headsWithPrefix($account . '@lot:'));
    }

    /**
     * Credits that can be used now (or at $at): what remains on lots that have not expired.
     */
    public function available(string $account, ?\DateTimeImmutable $at = null): Amount
    {
        $at ??= $this->clock->now();
        $total = Amount::zero();
        foreach ($this->store->headsWithPrefix($account . '@lot:') as $head) {
            if (!self::expired($head->account->code, $at)) {
                $total = $total->plus($head->balance);
            }
        }

        return $total;
    }

    /**
     * Uses credits: takes $amount from the lots that expire first and credits each lot's
     * revenue account. Throws InsufficientFunds when the lots that have not expired hold
     * less. A retry with the same key returns the first result.
     *
     * @param array<mixed> $meta string keys to strings, integers, booleans or null
     */
    public function consume(string $key, string $account, int|string|Amount $amount, array $meta = []): Transaction
    {
        $this->liability($account);
        $amount = $amount instanceof Amount ? $amount : Amount::of($amount);
        if (!$amount->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('Credits to use must be positive, got %s.', $amount));
        }
        $request = substr(hash('sha256', json_encode([$account, (string) $amount, $meta], \JSON_THROW_ON_ERROR)), 0, 24);
        $reserved = ['credits' => $account, 'request' => $request];

        try {
            return $this->store->transactional(function () use ($key, $account, $amount, $reserved, $meta, $request): Transaction {
                $existing = $this->store->transactionByKey($key);
                if ($existing !== null) {
                    return $this->sameRequest($existing, $request) ? $existing : throw new IdempotencyConflict(\sprintf('Key "%s" was already used for a different transaction.', $key));
                }
                $now = $this->clock->now();
                $codes = array_map(static fn(Head $h): string => $h->account->code, $this->store->headsWithPrefix($account . '@lot:'));
                sort($codes, \SORT_STRING);
                $legs = [];
                $credits = [];
                $left = $amount;
                foreach ($this->store->lock($codes) as $code => $head) {
                    if ($left->isZero()) {
                        break;
                    }
                    if (self::expired($code, $now) || !$head->balance->isPositive()) {
                        continue;
                    }
                    $take = $head->balance->compareTo($left) < 0 ? $head->balance : $left;
                    $revenue = $this->lotOf($code, $head->balance)->revenue;
                    $legs[] = Leg::debit($code, $take);
                    $credits[$revenue] = ($credits[$revenue] ?? Amount::zero())->plus($take);
                    $left = $left->minus($take);
                }
                if (!$left->isZero()) {
                    throw new InsufficientFunds(\sprintf(
                        'Account "%s" has %s credits that have not expired; %s requested.',
                        $account,
                        $amount->minus($left),
                        $amount,
                    ));
                }
                foreach ($credits as $revenue => $sum) {
                    $legs[] = Leg::credit((string) $revenue, $sum);
                }

                return $this->ledger->post($key, $legs, $reserved + $meta, 'credit.consume');
            });
        } catch (IdempotencyConflict | InsufficientFunds $e) {
            // From an old snapshot the key lookup can miss a consumption another process
            // committed: the plan then differs (a conflict) or the lots it emptied are short.
            // A locking read sees the latest commit.
            $stored = $this->store->transactionByKey($key, true);
            if ($stored !== null && $this->sameRequest($stored, $request)) {
                return $stored;
            }
            throw $e;
        }
    }

    /**
     * Gives back credits a consumption used: the job failed, or cost less than was taken.
     * The whole consumption by default, or $amount of it. Each lot gets back what was taken
     * from it and its revenue account is debited; a part is returned as if the consumption
     * had been that much smaller, so the lot that expires last is refilled first.
     *
     * A lot that has expired by now gets its credits back too, and they stay expired: the
     * next expire() moves them to breakage. Pass $reopenUntil to put them on a new lot the
     * customer can use until that moment instead.
     *
     * A consumption is refunded once (AlreadyReversed after that). A retry with the same
     * key returns the first refund.
     *
     * @param array<mixed> $meta string keys to strings, integers, booleans or null
     */
    public function refund(
        string $key,
        int $consumptionId,
        int|string|Amount|null $amount = null,
        ?\DateTimeImmutable $reopenUntil = null,
        array $meta = [],
    ): Transaction {
        $consumption = $this->store->transaction($consumptionId);
        if ($consumption === null || $consumption->type !== 'credit.consume') {
            throw new NotReversible(\sprintf('Transaction %d is not a consumption of credits.', $consumptionId));
        }
        $account = (string) $consumption->meta['credits'];
        $taken = [];
        foreach ($consumption->postings as $posting) {
            if (str_starts_with($posting->account, $account . '@lot:')) {
                $taken[$posting->account] = $posting->amount;
            }
        }
        $used = array_reduce($taken, static fn(Amount $sum, Amount $part): Amount => $sum->plus($part), Amount::zero());
        $amount = $amount === null ? $used : ($amount instanceof Amount ? $amount : Amount::of($amount));
        if (!$amount->isPositive() || $amount->compareTo($used) > 0) {
            throw new \InvalidArgumentException(\sprintf('A refund must be between 1 and the %s that transaction %d used, got %s.', $used, $consumptionId, $amount));
        }

        // Lots are used in the order of their codes; give back in the opposite order.
        krsort($taken, \SORT_STRING);
        $legs = [];
        $debits = [];
        $returned = [];
        $left = $amount;
        foreach ($taken as $code => $part) {
            if ($left->isZero()) {
                break;
            }
            $give = $part->compareTo($left) < 0 ? $part : $left;
            $lot = $this->lotOf($code, Amount::zero());
            $legs[] = Leg::credit($code, $give);
            $debits[$lot->revenue] = ($debits[$lot->revenue] ?? Amount::zero())->plus($give);
            $returned[$code] = [$lot, $give];
            $left = $left->minus($give);
        }
        foreach ($debits as $revenue => $sum) {
            $legs[] = Leg::debit((string) $revenue, $sum);
        }
        $reserved = [
            'credits' => $account,
            'reopen_until' => $reopenUntil?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];

        return $this->store->transactional(function () use ($key, $consumption, $legs, $reserved, $meta, $returned, $reopenUntil, $account): Transaction {
            $refund = $this->ledger->postUndoing($key, $consumption, $legs, $reserved + $meta, 'credit.refund');
            if ($reopenUntil === null) {
                return $refund;
            }
            // Which lots had expired is decided by the time of the refund, not of this
            // call, so a retry days later reopens the same lots under the same keys.
            foreach ($returned as $code => [$lot, $give]) {
                if (self::expired($code, $refund->createdAt)) {
                    $this->grant(
                        'credits:reopen:' . $refund->id . ':' . $lot->id,
                        $account,
                        $give,
                        from: $code,
                        revenue: $lot->revenue,
                        breakage: $lot->breakage,
                        expiresAt: $reopenUntil,
                        meta: ['reopens' => $lot->id],
                    );
                }
            }

            return $refund;
        });
    }

    /**
     * Moves what is left on the account's expired lots to their breakage accounts, one
     * transaction per lot. Running it again does nothing for lots already expired.
     *
     * @return list<Transaction>
     */
    public function expire(string $account, ?\DateTimeImmutable $at = null): array
    {
        $at ??= $this->clock->now();

        return $this->store->transactional(function () use ($account, $at): array {
            $codes = [];
            foreach ($this->store->headsWithPrefix($account . '@lot:') as $head) {
                if (self::expired($head->account->code, $at)) {
                    $codes[] = $head->account->code;
                }
            }
            sort($codes, \SORT_STRING);
            $expired = [];
            foreach ($this->store->lock($codes) as $code => $head) {
                if ($head->balance->isPositive()) {
                    $expired[] = $this->breakage($code, $head);
                }
            }

            return $expired;
        });
    }

    /**
     * expire() for every credits account in the ledger: a nightly job calls this instead of
     * looping over its customers. Each lot is expired in a transaction of its own, so a
     * run that stops halfway keeps what it did and the next run does the rest. Call it
     * outside a database transaction, or the locks of every lot are held to the end.
     *
     * Stops after $limit lots were expired and returns where to continue. Looking at a lot
     * that has nothing left costs a read, so the run time grows with the number of lots
     * ever granted, not with the number that expire tonight.
     */
    public function expireAll(?\DateTimeImmutable $at = null, int $limit = 1000, string $after = ''): ExpiryRun
    {
        $at ??= $this->clock->now();
        $expired = [];
        do {
            $page = $this->store->codesContaining('@lot:', $after, self::PAGE);
            foreach ($page as $code) {
                $after = $code;
                if (!self::expired($code, $at) || $this->store->head($code)?->balance->isPositive() !== true) {
                    continue;
                }
                $transaction = $this->store->transactional(function () use ($code): ?Transaction {
                    $head = $this->store->lock([$code])[$code] ?? null;

                    return $head !== null && $head->balance->isPositive() ? $this->breakage($code, $head) : null;
                });
                if ($transaction !== null) {
                    $expired[] = $transaction;
                }
                if (\count($expired) >= $limit) {
                    return new ExpiryRun($expired, $code);
                }
            }
        } while (\count($page) === self::PAGE);

        return new ExpiryRun($expired, null);
    }

    /**
     * Under the lot's lock: what is left on it goes to its breakage account.
     */
    private function breakage(string $code, Head $head): Transaction
    {
        $lot = $this->lotOf($code, $head->balance);
        // A refund can put credits back on a lot that was already emptied here.
        $key = 'credits:expire:' . $lot->id;
        if ($this->store->transactionByKey($key, true) !== null) {
            $key .= ':' . ($head->sequence + 1);
        }

        return $this->ledger->post(
            $key,
            [Leg::debit($code, $head->balance), Leg::credit($lot->breakage, $head->balance)],
            ['credits' => $lot->account, 'lot' => $lot->id],
            'credit.expire',
        );
    }

    private function lotOf(string $lotAccount, Amount $remaining, ?Transaction $grant = null): Lot
    {
        $grant ??= $this->store->firstTransaction($lotAccount);
        if ($grant === null || $grant->type !== 'credit.grant') {
            throw new \UnexpectedValueException(\sprintf('Lot account "%s" has no grant.', $lotAccount));
        }
        $granted = Amount::zero();
        foreach ($grant->postingsOf($lotAccount) as $posting) {
            $granted = $granted->plus($posting->amount);
        }
        $expiresAt = $grant->meta['expires_at'] ?? null;

        return new Lot(
            $grant->id,
            $grant->key,
            (string) $grant->meta['credits'],
            $lotAccount,
            $granted,
            $remaining,
            \is_string($expiresAt) ? new \DateTimeImmutable($expiresAt) : null,
            (string) $grant->meta['revenue'],
            (string) $grant->meta['breakage'],
        );
    }

    private function sameRequest(Transaction $transaction, string $request): bool
    {
        return $transaction->type === 'credit.consume' && ($transaction->meta['request'] ?? null) === $request;
    }

    private function liability(string $account): Account
    {
        $base = $this->store->account($account) ?? throw new UnknownAccount(\sprintf('Account "%s" is not open.', $account));
        if ($base->type !== AccountType::Liability) {
            throw new \InvalidArgumentException(\sprintf('Credits are owed to the customer: "%s" must be a liability, not %s.', $account, $base->type->value));
        }

        return $base;
    }

    private static function expired(string $lotAccount, \DateTimeImmutable $at): bool
    {
        $expiry = substr($lotAccount, (int) strrpos($lotAccount, '@lot:') + 5, 16);

        return $expiry !== self::NEVER && $expiry <= $at->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
}
