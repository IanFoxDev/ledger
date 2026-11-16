<?php

declare(strict_types=1);

namespace Example\Payments;

use IanFoxDev\Ledger\Account;
use IanFoxDev\Ledger\Ledger;
use IanFoxDev\Ledger\Leg;
use IanFoxDev\Ledger\Storage\PdoStore;
use IanFoxDev\Ledger\Transaction;

/**
 * What the provider reports, written to the ledger. Every write is keyed by the
 * provider's own id, so a callback that comes five times, or a capture that is booked
 * early from a refund callback and then arrives itself, is written once.
 */
final readonly class Books
{
    public const string CASH = 'cash:psp';
    public const string CURRENCY = 'USD';

    public function __construct(public Ledger $ledger) {}

    public static function connect(string $dsn): self
    {
        return new self(new Ledger(new PdoStore(new \PDO($dsn))));
    }

    public static function wallet(string $user): string
    {
        return 'user:' . $user;
    }

    public function openWallet(string $user): void
    {
        $this->ledger->open(Account::liability(self::wallet($user), self::CURRENCY));
    }

    /**
     * Money arrived at the provider and is owed to the user. Built only from fields that
     * do not change after capture, so a payment snapshot from a callback and one from a
     * later GET give the same content under the same key.
     *
     * @param array<string, mixed> $payment
     */
    public function captured(array $payment): ?Transaction
    {
        $amount = self::int($payment, 'captured_amount');
        if ($amount === 0) {
            return null;
        }
        $user = self::user($payment);

        return $this->ledger->post(
            'psp:' . self::string($payment, 'id') . ':captured',
            [Leg::debit(self::CASH, $amount), Leg::credit(self::wallet($user), $amount)],
            ['payment' => self::string($payment, 'id'), 'user' => $user],
            'deposit',
        );
    }

    /**
     * The provider sent money back to the user's card.
     *
     * @param array<string, mixed> $refund
     * @param array<string, mixed> $payment
     */
    public function refunded(array $refund, array $payment): Transaction
    {
        $amount = self::int($refund, 'amount');
        $user = self::user($payment);

        return $this->ledger->post(
            'psp:' . self::string($refund, 'id'),
            [Leg::debit(self::wallet($user), $amount), Leg::credit(self::CASH, $amount)],
            ['payment' => self::string($payment, 'id'), 'refund' => self::string($refund, 'id'), 'user' => $user],
            'deposit.refund',
        );
    }

    /**
     * @param array<string, mixed> $payment
     */
    private static function user(array $payment): string
    {
        $metadata = $payment['metadata'] ?? null;
        $user = \is_array($metadata) ? ($metadata['user'] ?? null) : null;

        return \is_string($user) && $user !== '' ? $user : throw new \UnexpectedValueException('Payment has no metadata.user.');
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function string(array $object, string $field): string
    {
        $value = $object[$field] ?? null;

        return \is_string($value) ? $value : throw new \UnexpectedValueException("Field $field is not a string.");
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function int(array $object, string $field): int
    {
        $value = $object[$field] ?? null;

        return \is_int($value) ? $value : throw new \UnexpectedValueException("Field $field is not an integer.");
    }
}
