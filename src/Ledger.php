<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

use IanFoxDev\Ledger\Exception\AccountConflict;
use IanFoxDev\Ledger\Exception\IdempotencyConflict;
use IanFoxDev\Ledger\Exception\InsufficientFunds;
use IanFoxDev\Ledger\Exception\UnbalancedTransaction;
use IanFoxDev\Ledger\Exception\UnknownAccount;
use IanFoxDev\Ledger\Storage\Draft;
use IanFoxDev\Ledger\Storage\DuplicateKey;
use IanFoxDev\Ledger\Storage\Store;

final class Ledger
{
    private readonly Clock $clock;

    public function __construct(
        private readonly Store $store,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Opens an account. Opening it again with the same definition does nothing; with a
     * different type, currency or negative-balance setting it throws.
     */
    public function open(Account $account): Account
    {
        return $this->store->transactional(function () use ($account): Account {
            $this->store->addAccount($account);
            $stored = $this->store->account($account->code);
            if ($stored === null || !$stored->sameAs($account)) {
                throw new AccountConflict(\sprintf(
                    'Account "%s" is already open as %s %s%s.',
                    $account->code,
                    $stored?->type->value,
                    $stored?->currency,
                    $stored?->allowNegative === true ? ', allowed to go negative' : '',
                ));
            }

            return $stored;
        });
    }

    /**
     * Posts a transaction made of debit and credit legs. Per currency, debits must equal
     * credits. Posting the same key with the same content again returns the stored
     * transaction.
     *
     * @param list<Leg> $legs
     * @param array<mixed> $meta string keys to strings, integers, booleans or null
     */
    public function post(string $key, array $legs, array $meta = [], string $type = 'transaction'): Transaction
    {
        return $this->write($key, $legs, self::meta($meta), $type, null);
    }

    /**
     * Moves an amount from one account to another with the same normal side: the balance
     * of $from goes down and the balance of $to goes up. A user paying the platform is a
     * transfer from the user's wallet (liability) to revenue. A deposit from a payment
     * provider (asset) into a wallet (liability) is not: post it with explicit legs.
     *
     * @param array<mixed> $meta string keys to strings, integers, booleans or null
     */
    public function transfer(
        string $key,
        string $from,
        string $to,
        int|string|Amount $amount,
        array $meta = [],
        string $type = 'transfer',
    ): Transaction {
        $source = $this->store->account($from) ?? throw new UnknownAccount(\sprintf('Account "%s" is not open.', $from));
        $target = $this->store->account($to) ?? throw new UnknownAccount(\sprintf('Account "%s" is not open.', $to));
        $side = $target->type->normalSide();
        if ($source->type->normalSide() !== $side) {
            throw new \InvalidArgumentException(\sprintf(
                'A transfer moves money between accounts with the same normal side; "%s" is %s and "%s" is %s. Post explicit debit and credit legs instead.',
                $from,
                $source->type->value,
                $to,
                $target->type->value,
            ));
        }

        return $this->post($key, [new Leg($from, $side->opposite(), $amount), new Leg($to, $side, $amount)], $meta, $type);
    }

    public function balance(string $account): Amount
    {
        $head = $this->store->head($account) ?? throw new UnknownAccount(\sprintf('Account "%s" is not open.', $account));

        return $head->balance;
    }

    public function transaction(int $id): ?Transaction
    {
        return $this->store->transaction($id);
    }

    /**
     * @param list<Leg> $legs
     * @param array<string, string|int|bool|null> $meta
     */
    private function write(string $key, array $legs, array $meta, string $type, ?int $reverses): Transaction
    {
        if ($key === '' || \strlen($key) > 190) {
            throw new \InvalidArgumentException('An idempotency key must be 1 to 190 bytes.');
        }
        if ($legs === []) {
            throw new \InvalidArgumentException('A transaction needs at least one leg.');
        }
        $hash = Canonical::hash($type, $legs, $meta, $reverses);

        return $this->store->transactional(function () use ($key, $legs, $meta, $type, $reverses, $hash): Transaction {
            $codes = array_values(array_unique(array_map(static fn(Leg $l): string => $l->account, $legs)));
            sort($codes, \SORT_STRING);
            $heads = $this->store->lock($codes);

            $existing = $this->store->transactionByKey($key);
            if ($existing !== null) {
                if ($existing->hash !== $hash) {
                    throw $this->conflict($key, $existing->hash, $hash);
                }

                return $existing;
            }

            foreach ($codes as $code) {
                if (!isset($heads[$code])) {
                    throw new UnknownAccount(\sprintf('Account "%s" is not open.', $code));
                }
            }
            $this->checkBalanced($legs, $heads);

            $postings = [];
            foreach ($this->ordered($legs, $heads) as $leg) {
                $head = $heads[$leg->account];
                $delta = $head->account->direction($leg->side) > 0 ? $leg->amount : $leg->amount->negated();
                $balance = $head->balance->plus($delta);
                if ($balance->isNegative() && !$head->account->allowNegative) {
                    throw new InsufficientFunds(\sprintf(
                        'Account "%s" has %s and cannot go to %s.',
                        $leg->account,
                        $head->balance,
                        $balance,
                    ));
                }
                $heads[$leg->account] = new Storage\Head($head->account, $head->sequence + 1, $balance);
                $postings[] = new Posting($leg->account, $head->sequence + 1, $leg->side, $leg->amount, $balance);
            }

            try {
                return $this->store->append(new Draft($key, $hash, $type, $postings, $meta, $this->clock->now(), $reverses));
            } catch (DuplicateKey) {
                // The same content locks the same accounts and would have waited for the
                // first writer, so a key taken in between was used for something else.
                throw $this->conflict($key, null, $hash);
            }
        });
    }

    /**
     * @param list<Leg> $legs
     * @param array<string, Storage\Head> $heads
     */
    private function checkBalanced(array $legs, array $heads): void
    {
        /** @var array<string, Amount> $net */
        $net = [];
        foreach ($legs as $leg) {
            $currency = $heads[$leg->account]->account->currency;
            $signed = $leg->side === Side::Debit ? $leg->amount : $leg->amount->negated();
            $net[$currency] = ($net[$currency] ?? Amount::zero())->plus($signed);
        }
        foreach ($net as $currency => $difference) {
            if (!$difference->isZero()) {
                throw new UnbalancedTransaction(\sprintf(
                    'Debits and credits in %s differ by %s.',
                    $currency,
                    $difference,
                ));
            }
        }
    }

    /**
     * Per account, legs that raise the balance come before legs that lower it, so an
     * account that ends at zero or above never shows a negative balance in between.
     *
     * @param list<Leg> $legs
     * @param array<string, Storage\Head> $heads
     * @return list<Leg>
     */
    private function ordered(array $legs, array $heads): array
    {
        $keyed = [];
        foreach ($legs as $i => $leg) {
            $keyed[] = ['rank' => [$leg->account, -$heads[$leg->account]->account->direction($leg->side), $i], 'leg' => $leg];
        }
        usort($keyed, static fn(array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn(array $k): Leg => $k['leg'], $keyed);
    }

    /**
     * @param array<mixed> $meta
     * @return array<string, string|int|bool|null>
     */
    private static function meta(array $meta): array
    {
        $checked = [];
        foreach ($meta as $name => $value) {
            if (!\is_string($name) || !(\is_string($value) || \is_int($value) || \is_bool($value) || $value === null)) {
                throw new \InvalidArgumentException('Meta is a map of string keys to strings, integers, booleans or null; floats are not allowed.');
            }
            $checked[$name] = $value;
        }

        return $checked;
    }

    private function conflict(string $key, ?string $stored, string $given): IdempotencyConflict
    {
        return new IdempotencyConflict(\sprintf(
            'Key "%s" was already used for a different transaction%s; this call has %s.',
            $key,
            $stored === null ? '' : ' (content ' . substr($stored, 0, 12) . ')',
            substr($given, 0, 12),
        ));
    }
}
