<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Amount;
use IanFoxDev\Ledger\Posting;
use IanFoxDev\Ledger\Transaction;

/**
 * Keeps the ledger in memory, for tests. A transaction that throws leaves nothing behind.
 */
final class InMemoryStore implements Store
{
    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<int, Transaction> */
    private array $transactions = [];

    /** @var array<string, int> */
    private array $keys = [];

    /** @var array<string, list<Posting>> */
    private array $postings = [];

    private bool $open = false;

    public function transactional(callable $fn): mixed
    {
        if ($this->open) {
            return $fn();
        }
        $saved = [$this->accounts, $this->transactions, $this->keys, $this->postings];
        $this->open = true;
        try {
            return $fn();
        } catch (\Throwable $e) {
            [$this->accounts, $this->transactions, $this->keys, $this->postings] = $saved;
            throw $e;
        } finally {
            $this->open = false;
        }
    }

    public function account(string $code): ?Account
    {
        return $this->accounts[$code] ?? null;
    }

    public function addAccount(Account $account): bool
    {
        if (isset($this->accounts[$account->code])) {
            return false;
        }
        $this->accounts[$account->code] = $account;

        return true;
    }

    public function lock(array $codes): array
    {
        $heads = [];
        foreach ($codes as $code) {
            $head = $this->head($code);
            if ($head !== null) {
                $heads[$code] = $head;
            }
        }

        return $heads;
    }

    public function head(string $code): ?Head
    {
        $account = $this->accounts[$code] ?? null;
        if ($account === null) {
            return null;
        }
        $chain = $this->postings[$code] ?? [];
        $last = $chain === [] ? null : $chain[\count($chain) - 1];

        return new Head($account, $last->sequence ?? 0, $last->balanceAfter ?? Amount::zero());
    }

    public function transactionByKey(string $key): ?Transaction
    {
        return isset($this->keys[$key]) ? $this->transactions[$this->keys[$key]] : null;
    }

    public function transaction(int $id): ?Transaction
    {
        return $this->transactions[$id] ?? null;
    }

    public function append(Draft $draft): Transaction
    {
        if (isset($this->keys[$draft->key])) {
            throw new DuplicateKey($draft->key);
        }
        $id = \count($this->transactions) + 1;
        $transaction = new Transaction(
            $id,
            $draft->key,
            $draft->hash,
            $draft->type,
            $draft->postings,
            $draft->meta,
            $draft->createdAt,
            $draft->reverses,
        );
        $this->transactions[$id] = $transaction;
        $this->keys[$draft->key] = $id;
        foreach ($draft->postings as $posting) {
            $this->postings[$posting->account][] = $posting;
        }

        return $transaction;
    }

    /**
     * Every posting of an account in order, for tests that recalculate balances.
     *
     * @return list<Posting>
     */
    public function postingsOf(string $code): array
    {
        return $this->postings[$code] ?? [];
    }

    /**
     * @return list<Account>
     */
    public function accounts(): array
    {
        return array_values($this->accounts);
    }
}
