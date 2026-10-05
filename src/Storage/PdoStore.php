<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Storage;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\AccountType;
use IanFoxDev\Ledger\Amount;
use IanFoxDev\Ledger\Posting;
use IanFoxDev\Ledger\Side;
use IanFoxDev\Ledger\Transaction;

/**
 * Keeps the ledger in PostgreSQL or MySQL, in the tables from schema/postgresql.sql or
 * schema/mysql.sql. Give it the connection your application uses, so the ledger writes in
 * your transaction.
 */
final readonly class PdoStore implements Store
{
    private bool $mysql;

    public function __construct(private \PDO $pdo)
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'pgsql' && $driver !== 'mysql') {
            throw new \InvalidArgumentException(\sprintf('PdoStore supports pgsql and mysql, not %s.', \is_string($driver) ? $driver : '?'));
        }
        $this->mysql = $driver === 'mysql';
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function transactional(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->pdo->commit();

        return $result;
    }

    public function account(string $code): ?Account
    {
        $row = $this->row('SELECT code, type, currency, allow_negative FROM ledger_accounts WHERE code = ?', [$code]);

        return $row === null ? null : $this->accountFrom($row);
    }

    public function addAccount(Account $account): bool
    {
        $values = [$account->code, $account->type->value, $account->currency, $account->allowNegative ? 1 : 0];
        if ($this->mysql) {
            // INSERT IGNORE would also hide truncation; the values are validated, but a
            // no-op update on the key is exact about what it ignores.
            $statement = $this->pdo->prepare('INSERT INTO ledger_accounts (code, type, currency, allow_negative) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE code = code');
            $statement->execute($values);

            return $statement->rowCount() === 1;
        }
        $statement = $this->pdo->prepare('INSERT INTO ledger_accounts (code, type, currency, allow_negative) VALUES (?, ?, ?, ?::boolean) ON CONFLICT (code) DO NOTHING');
        $statement->execute([$values[0], $values[1], $values[2], $account->allowNegative ? 'true' : 'false']);

        return $statement->rowCount() === 1;
    }

    public function lock(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, \count($codes), '?'));
        $order = $this->mysql ? 'code' : 'code COLLATE "C"';
        $statement = $this->pdo->prepare("SELECT code, type, currency, allow_negative FROM ledger_accounts WHERE code IN ($placeholders) ORDER BY $order FOR UPDATE");
        $statement->execute($codes);
        $heads = [];
        foreach ($this->rows($statement) as $row) {
            $account = $this->accountFrom($row);
            $heads[$account->code] = $this->headOf($account, true);
        }

        return $heads;
    }

    public function head(string $code): ?Head
    {
        $account = $this->account($code);

        return $account === null ? null : $this->headOf($account, false);
    }

    public function transactionByKey(string $key, bool $locking = false): ?Transaction
    {
        $suffix = $locking && $this->mysql ? ' FOR SHARE' : '';
        $row = $this->row('SELECT id, idempotency_key, hash, type, meta, reverses, created_at FROM ledger_transactions WHERE idempotency_key = ?' . $suffix, [$key]);

        return $row === null ? null : $this->transactionFrom($row);
    }

    public function transaction(int $id): ?Transaction
    {
        $row = $this->row('SELECT id, idempotency_key, hash, type, meta, reverses, created_at FROM ledger_transactions WHERE id = ?', [$id]);

        return $row === null ? null : $this->transactionFrom($row);
    }

    public function append(Draft $draft): Transaction
    {
        $meta = json_encode($draft->meta === [] ? new \stdClass() : self::sorted($draft->meta), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $values = [$draft->key, $draft->hash, $draft->type, $meta, $draft->reverses, $draft->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')];
        if ($this->mysql) {
            try {
                $this->pdo->prepare('INSERT INTO ledger_transactions (idempotency_key, hash, type, meta, reverses, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute($values);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'ledger_transactions_key')) {
                    throw new DuplicateKey($draft->key, 0, $e);
                }
                throw $e;
            }
            $id = (int) $this->pdo->lastInsertId();
        } else {
            // ON CONFLICT keeps the transaction usable, so the ledger can read the row that won.
            $statement = $this->pdo->prepare('INSERT INTO ledger_transactions (idempotency_key, hash, type, meta, reverses, created_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (idempotency_key) DO NOTHING RETURNING id');
            $statement->execute($values);
            $id = $statement->fetchColumn();
            if ($id === false) {
                throw new DuplicateKey($draft->key);
            }
            $id = self::int($id);
        }

        $insert = $this->pdo->prepare('INSERT INTO ledger_postings (account, sequence, transaction_id, position, side, amount, balance_after) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($draft->postings as $position => $posting) {
            $insert->execute([$posting->account, $posting->sequence, $id, $position, $posting->side->value, (string) $posting->amount, (string) $posting->balanceAfter]);
        }

        return new Transaction($id, $draft->key, $draft->hash, $draft->type, $draft->postings, $draft->meta, $draft->createdAt, $draft->reverses);
    }

    private function headOf(Account $account, bool $locked): Head
    {
        // In MySQL a locking read sees the latest commit even under REPEATABLE READ.
        $suffix = $locked && $this->mysql ? ' FOR UPDATE' : '';
        $row = $this->row('SELECT sequence, balance_after FROM ledger_postings WHERE account = ? ORDER BY sequence DESC LIMIT 1' . $suffix, [$account->code]);

        return $row === null
            ? new Head($account, 0, Amount::zero())
            : new Head($account, self::int($row['sequence']), Amount::of(self::string($row['balance_after'])));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function transactionFrom(array $row): Transaction
    {
        $id = self::int($row['id']);
        $statement = $this->pdo->prepare('SELECT account, sequence, side, amount, balance_after FROM ledger_postings WHERE transaction_id = ? ORDER BY position');
        $statement->execute([$id]);
        $postings = [];
        foreach ($this->rows($statement) as $p) {
            $postings[] = new Posting(
                self::string($p['account']),
                self::int($p['sequence']),
                Side::from(self::string($p['side'])),
                Amount::of(self::string($p['amount'])),
                Amount::of(self::string($p['balance_after'])),
            );
        }
        $meta = json_decode(self::string($row['meta']), true, 4, \JSON_THROW_ON_ERROR);
        $checked = [];
        foreach (\is_array($meta) ? $meta : [] as $name => $value) {
            if (\is_string($value) || \is_int($value) || \is_bool($value) || $value === null) {
                $checked[(string) $name] = $value;
            }
        }

        return new Transaction(
            $id,
            self::string($row['idempotency_key']),
            self::string($row['hash']),
            self::string($row['type']),
            $postings,
            $checked,
            new \DateTimeImmutable(self::string($row['created_at']), new \DateTimeZone('UTC')),
            $row['reverses'] === null ? null : self::int($row['reverses']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function accountFrom(array $row): Account
    {
        return new Account(
            self::string($row['code']),
            AccountType::from(self::string($row['type'])),
            self::string($row['currency']),
            \in_array($row['allow_negative'], [true, 1, '1', 't', 'true'], true),
        );
    }

    /**
     * @param list<string|int> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $this->rows($statement);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(\PDOStatement $statement): array
    {
        $rows = [];
        while (\is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
            $typed = [];
            foreach ($row as $column => $value) {
                $typed[(string) $column] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    /**
     * @param array<string, string|int|bool|null> $meta
     * @return array<string, string|int|bool|null>
     */
    private static function sorted(array $meta): array
    {
        ksort($meta, \SORT_STRING);

        return $meta;
    }

    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Expected an integer column, got ' . get_debug_type($value) . '.');
    }

    private static function string(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        throw new \UnexpectedValueException('Expected a text column, got ' . get_debug_type($value) . '.');
    }
}
